<?php

namespace App\Services;

use App\Contracts\IntelligenceProvider;
use App\Support\CompanyClock;
use App\Support\Money;
use Illuminate\Support\Str;

/** Read-only composition of the role's existing queues. */
final class AssistantWorkGuidance
{
    private const TOPICS = [
        'day' => 'What to work on first or next in a shift, team, role or today. No named record.',
        'pick' => 'Orders ready to pick: pick, collect, mbledh. Separate from dispatch.',
        'count' => 'Inventory stock counting, recount or count to review.',
        'blocked' => 'An order cannot be shipped, dispatched or completed. The employee asks what is blocking it.',
        'receipts' => 'Goods expected from suppliers are late or past the promised receipt date.',
        'supplier' => 'Repeated supplier delays or historical reliability. Separate from currently late receipts.',
        'approvals' => 'A purchase order is waiting for a sign-off or approval. Review only.',
        'collections' => 'Which customers to call about past-due balances or invoices.',
        'bank' => 'Bank or cash reconciliation with accounting books, not a balance question.',
        'close' => 'Work still open before signing off an accounting month or period.',
    ];

    public function reply(array $input, string $conversation, array $context): ?array
    {
        $question = $input['question'];
        $plan = app(AssistantPlanner::class)->plan($question);
        $q = $plan['normalized'] ?? '';
        if (in_array($plan['intent'], ['unsupported', 'prepare', 'scenario', 'confirmation_required', 'suppliers'], true) || isset($input['simulation_id']) || preg_match('/\b(?:what if|nese|simulate|simulo)\b/', $q)) {
            return null;
        }
        if (isset($input['customer_id']) || isset($input['entity']) && $input['entity']['type'] !== 'sales_order') {
            return null;
        }
        $topic = match (true) {
            (bool) preg_match('/\b(?:why|pse).*(?:cannot|cant|not|nuk|spo).*\b(?:ship|dispatch|orders?|nisj\w*)\b/', $q) => 'blocked',
            (bool) preg_match('/\b(?:orders?|porosi\w*)\b.*\b(?:gati me u nis|ready (?:to ship|for dispatch|to dispatch))\b/', $q) => 'packed',
            (bool) preg_match('/\b(?:pick|mbledh)\w*\b.*\borders?\b|\borders?\b.*\b(?:pick|mbledh)\w*\b|\bpicking queue\b/', $q) => 'pick',
            (bool) preg_match('/\b(?:counts?|counting|numerim\w*|recount)\b.*\b(?:stock|check|review|warehouse)\b|\b(?:stock|inventory).*\b(?:counts?|counting|recount)\b/', $q) => 'count',
            (bool) preg_match('/\b(?:purchase requests?|kerkes\w* (?:per )?blerj\w*)\b.*\b(?:review|pending|waiting|shqyrt\w*|presin)\b/', $q) => 'requests',
            (bool) preg_match('/\b(?:purchases?|purchase orders?|(?:order|porosi\w*) (?:te )?blerj\w*)\b.*\b(?:sign.off|approval|miratim\w*)\b/', $q) => 'approvals',
            (bool) preg_match('/\b(?:tasks?|detyr\w*)\b.*\b(?:assigned to me|caktu\w* per mua)\b|\bmy (?:automation )?tasks\b/', $q) => 'my_tasks',
            (bool) preg_match('/^(?:what|which|cka)\b.*\b(?:my attention|attention my|vemend\w* tim\w*)\b[?.! ]*(?:today)?[?.! ]*$/', $q) => 'day',
            (bool) preg_match('/\b(?:today|morning|shift)\b/', $q) && (bool) preg_match('/\b(?:what|which|any|ku|a kemi)\b.*\b(?:do|work|first|priority|priorities|radhe|pune|punet|urgent|problem\w*|prioritet\w*|se pari|ia nis)\b/', $q) && ! preg_match('/\b(?:changed|afford|ship|dispatch|quote\w*|ofert\w*|follow.up|late|overdue|unpaid|payments?|deliver\w*|receipt\w*|orders?|reorder|sold|sales)\b/', $q) => 'day',
            default => null,
        };
        $proposal = null;
        if (! $topic && $this->needsInterpretation($q) && ! app(FinanceAssistantFacts::class)->intent($question, $conversation)) {
            $proposal = $this->propose($input);
            $topic = $proposal['topic'] ?? null;
            if ($topic === 'blocked' && ! app(AssistantBusinessIntent::class)->fulfillmentOperation($question,
                isset($input['entity']) || isset($context['fulfillment_order_id']))) {
                return null;
            }
            if ($topic === 'bank' && ! preg_match('/\b(?:reconcil\w*|agree\w*|ledger|books|rakord\w*|perputh\w*|libr\w*)\b/', $q)) {
                return null;
            }
            if ($topic === 'supplier' && ! preg_match('/\b(?:history|histor\w*|repeated\w*|often|keeps?|miss\w*|shpesh|gjithmone)\b/', $q)) {
                $topic = 'receipts';
            }
        }
        if (! $topic) {
            return null;
        }
        $sq = ($input['language'] ?? 'en') === 'sq';
        if (in_array($topic, ['collections', 'bank', 'close'], true)) {
            $intent = match ($topic) {
                'collections' => 'finance_receivables',
                'bank' => 'finance_reconciliation',
                'close' => 'finance_close',
            };
            $tool = match ($topic) {
                'collections' => 'get_accounting_receivables',
                'bank' => 'get_financial_reconciliation',
                'close' => 'get_month_close_blockers',
            };
            if (app(AssistantToolRunner::class)->allowed($tool)) {
                $reply = app(FinanceAssistantFacts::class)->reply($intent, $question, $conversation, $sq, $topic === 'collections');
                return $this->withProposal($reply, $proposal);
            }
            $text = $sq ? 'Kjo llogari nuk ka leje për këtë shqyrtim financiar.' : 'This account cannot view this financial review.';
            return $this->withProposal($this->answer($conversation, $topic, $text, [], [], 'permission_denied'), $proposal);
        }
        if ($topic === 'blocked') {
            return $this->withProposal($this->orderBlocker($input, $context, $conversation, $sq), $proposal);
        }

        $role = auth()->user()->role;
        $views = $topic === 'day' ? match ($role) {
            'warehouse' => ['pick', 'packed', 'incoming', 'counts', 'shortage'],
            'sales' => ['quotes', 'sales_orders', 'followups', 'overdue_ar'],
            'purchasing' => ['requests', 'approvals', 'late_receipts', 'replenishment'],
            'accountant', 'finance' => ['bank', 'payables', 'close'],
            default => ['brief'],
        } : match ($topic) {
            'count' => ['counts'],
            'receipts', 'supplier' => ['late_receipts'],
            default => [$topic],
        };
        $runner = app(AssistantToolRunner::class);
        $cards = [];
        foreach ($views as $view) {
            $card = $this->queue($view, $sq, $runner, $view === 'my_tasks' && preg_match('/\bautomat\w*\b/', $q));
            if ($card) {
                $cards[] = $card;
            }
        }
        // Put confirmed work before an empty queue. The labels still describe each source.
        usort($cards, fn ($a, $b) => $b['has_work'] <=> $a['has_work']);
        $lead = $cards[0] ?? null;
        $text = $lead ? $lead['summary'] : ($sq ? 'Kjo llogari nuk ka qasje në këtë radhë pune.' : 'This account cannot access this work queue.');
        if ($lead && $topic === 'day') {
            $text = $lead['has_work']
                ? ($sq ? 'Fillo me ' : 'Start with ').$lead['title'].'. '.$text
                : (in_array(true, array_column($cards, 'source_unavailable'), true)
                    ? ($sq ? 'Nuk u kontrolluan të gjitha burimet e punës. Hap burimin për të parë çfarë mund të verifikosh.' : 'Some work could not be checked. Open the source to see what you can verify.')
                    : ($sq ? 'Burimet e kontrolluara nuk tregojnë punë që kërkon veprim tani.' : 'The queues checked show no work requiring action now.'));
        }
        if ($lead && $topic === 'supplier') {
            $text = ($sq ? 'Burimi tregon vetëm pranimet që janë vonë tani. Për të provuar vonesa të përsëritura duhen afatet e premtuara dhe pranimet për një periudhë.' : 'This source shows receipts that are late now. Repeated delays need promised and received dates over a defined period.').' '.$text;
        }
        if ($topic === 'approvals' && ! $lead && $runner->allowed('get_purchase_order')) {
            $text = $sq ? 'Roli yt nuk ka leje për radhën e miratimeve. Mund të hapësh një porosi blerjeje për të parë gjendjen e saj.' : 'Your role cannot open the approvals queue. You can still open an individual purchase order and check its status.';
        }
        $reply = $this->answer($conversation, $topic, $text, $cards, $runner->executions, $lead ? 'success' : 'permission_denied');
        if ($topic === 'approvals' && ! $lead && $runner->allowed('get_purchase_order')) {
            $reply['next_action'] = ['url' => '/purchase-orders', 'label' => $sq ? 'Hap porositë e blerjes' : 'Open Purchase Orders'];
        }
        if ($topic === 'day' && $lead) {
            $reply['limitations'][] = $sq ? 'Përfshihen vetëm burimet e lejuara më poshtë; kjo pamje nuk cakton detyra personale.' : 'Only the permitted sources below were checked. This view does not assign personal tasks.';
        }
        return $this->withProposal($reply, $proposal);
    }

    private function needsInterpretation(string $q): bool
    {
        // Broad work vocabulary gates a compact model proposal without intercepting ordinary record queries.
        if (preg_match('/\b(?:stock|available|metra|meters)\b.*\d|\b(?:quote\w*|ofert\w*|payroll|fiscal\w*|reorder|replenish|riporos\w*|what changed|compare|krahas\w*)\b/', $q)) {
            return false;
        }
        return (bool) preg_match('/\b(?:first|start|begin|tackle|shift|morning|today|nis\w*|radhe|nder\w*|numerim\w*|count\w*|late|past due|vonu\w*|vones\w*|miss\w*|premtu\w*|ardh\w*|sign off|aprov\w*|approval|agree|reconcil\w*|perputh\w*|clos\w*|mbyll\w*)\b/', $q);
    }

    private function propose(array $input): ?array
    {
        $provider = app(IntelligenceProvider::class);
        if (! config('assistant.model_tools') || ! $provider->available()) {
            return null;
        }
        $name = 'propose_work_topic';
        $schema = ['type' => 'object', 'required' => ['topic'], 'properties' => [
            'topic' => ['type' => 'string', 'enum' => [...array_keys(self::TOPICS), 'other']],
        ]];
        $actor = auth()->user();
        $result = $provider->respond([['role' => 'user', 'content' => json_encode([
            'role' => $actor->role,
            'question' => $input['question'],
            'instruction' => 'Propose one topic only. Use other for factual requests outside the listed work topics. This proposal does not retrieve or change anything.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]], [[
            'name' => $name,
            'description' => implode(' ', array_map(fn ($key, $text) => "$key: $text", array_keys(self::TOPICS), self::TOPICS)),
            'schema' => $schema,
        ]]);
        $calls = $result['plan']['calls'] ?? [];
        $topic = $calls[0]['arguments']['topic'] ?? null;
        if (($result['state'] ?? null) !== 'available' || count($calls) !== 1 || ($calls[0]['tool'] ?? null) !== $name
            || ! is_string($topic) || ! isset(self::TOPICS[$topic]) || array_keys($calls[0]['arguments']) !== ['topic']) {
            return null;
        }
        $current = $actor->fresh(['company']);
        abort_unless($current && (int) $current->company_id === (int) $actor->company_id, 403);
        \Illuminate\Support\Facades\Auth::setUser($current);
        return ['topic' => $topic, 'model' => $result['model'] ?? null, 'timing' => $result['timing'] ?? null];
    }

    private function queue(string $view, bool $sq, AssistantToolRunner $runner, bool $automationOnly = false): ?array
    {
        [$tool, $args, $url, $en, $al] = match ($view) {
            'pick' => ['get_pick_queue', [], '/fulfillment?status=allocated', 'Picking', 'Përgatitja e porosive'],
            'packed' => ['get_orders_needing_attention', ['view' => 'packed'], '/fulfillment?status=packed', 'Dispatch review', 'Kontrolli para nisjes'],
            'incoming' => ['get_incoming_supply', ['window' => 'upcoming'], '/purchase-orders', 'Expected receipts', 'Pranimet e pritshme'],
            'counts' => ['get_count_reviews', [], '/operations-center?tab=counts', 'Stock counts', 'Numërimet e stokut'],
            'shortage' => ['get_low_stock_facts', [], '/products', 'Current shortages', 'Stoku nën prag'],
            'quotes' => ['get_quotation_followups', [], '/customers?view=quotations', 'Quotations', 'Ofertat'],
            'sales_orders' => ['get_orders_needing_attention', ['view' => 'attention'], '/order-hub?view=attention', 'Orders to review', 'Porositë për shqyrtim'],
            'followups' => ['get_sales_followups', [], '/action-center?view=mine&status=active', 'Customer follow-ups', 'Kontaktet me klientët'],
            'my_tasks' => ['get_my_tasks', ['assigned_only' => true, 'automation_only' => $automationOnly], '/action-center?view=mine', 'My assigned tasks', 'Detyrat e caktuara për mua'],
            'overdue_ar' => ['get_accounting_receivables', [], app(FinanceAssistantFacts::class)->receivablesUrl(), 'Overdue customer balances', 'Borxhet e vonuara të klientëve'],
            'requests' => ['get_purchase_request_reviews', [], '/procurement', 'Purchase requests', 'Kërkesat për blerje'],
            'approvals' => ['get_pending_approvals', [], '/procurement?view=approvals', 'Purchase-order approvals', 'Miratimet e porosive të blerjes'],
            'late_receipts' => ['get_incoming_supply', ['window' => 'overdue'], '/purchase-orders', 'Late receipts', 'Pranimet e vonuara'],
            'replenishment' => ['get_products_needing_replenishment', [], '/inventory-intelligence', 'Replenishment review', 'Shqyrtimi i rifurnizimit'],
            'bank' => ['get_financial_reconciliation', [], '/accounting?tab=reconciliation', 'Reconciliation', 'Rakordimi'],
            'payables' => ['get_accounting_payables', [], '/accounting?tab=ap', 'Supplier bills due', 'Pagesat ndaj furnitorëve'],
            'close' => ['get_month_close_blockers', [], '/accounting?tab=periods', 'Period close', 'Mbyllja e periudhës'],
            'brief' => ['get_operating_brief', ['view' => 'management', 'days' => 1], '/action-center', 'Business priorities', 'Përparësitë e biznesit'],
        };
        $permissions = app(PermissionService::class);
        $role = auth()->user()->role;
        if (! $runner->allowed($tool)
            || $view === 'quotes' && ! $permissions->roleHasPermission($role, 'fulfillment.view')
            || $view === 'followups' && ! $permissions->roleHasPermission($role, 'tasks.view')
            || $view === 'approvals' && ! $permissions->roleHasPermission($role, 'procurement.view')
            || in_array($view, ['payables', 'bank', 'close'], true) && ! $permissions->roleHasPermission($role, 'accounting.reports.view')) {
            return null;
        }
        $data = $runner->execute($tool, $args);
        $rows = match ($view) {
            'pick', 'counts', 'requests' => $data,
            'approvals' => array_values(array_filter($data, fn ($row) => ($row['entity_type'] ?? null) === 'purchase_order')),
            'packed', 'sales_orders', 'my_tasks' => $data['data'] ?? [],
            'brief' => $data['attention'] ?? [],
            'bank' => array_values(array_filter($data['rows'] ?? [], fn ($row) => ! ($row['reconciled'] ?? false))),
            'close' => $data['blockers'] ?? [],
            'overdue_ar' => $data['top_overdue_customers'] ?? [],
            'payables' => $data['items'] ?? [],
            'shortage' => $data['products'] ?? [],
            'replenishment' => array_values(array_filter($data['rows'] ?? [], fn ($row) => data_get($row, 'plan.coverage_supported') === true)),
            default => $data['rows'] ?? [],
        };
        $lead = isset($rows[0]) ? (array) $rows[0] : [];
        $ref = $lead['reference'] ?? $lead['count_number'] ?? $lead['request_number'] ?? $lead['number'] ?? $lead['customer_name']
            ?? data_get($lead, 'context.po_number') ?? data_get($lead, 'order.order_number') ?? OperatingBriefSummary::displayTitle($lead, $sq) ?? $lead['label'] ?? $lead['name'] ?? data_get($lead, 'product.name');
        $detail = $lead['status'] ?? $lead['expected_at'] ?? null;
        $count = count($rows);
        $title = $sq ? $al : $en;
        $summary = $count
            ? ($sq ? "$count në listën e kontrolluar." : "$count in the reviewed list.").($ref ? " $ref".($detail ? " · $detail" : '').'.' : '')
            : ($sq ? 'Nuk ka regjistrime në listën e kontrolluar.' : 'No entries in the reviewed list.');
        $sourceUnavailable = $view === 'brief' && in_array('attention', $data['unavailable'] ?? [], true);
        if ($sourceUnavailable) {
            $summary = $sq ? 'Burimi i prioriteteve nuk u përgjigj. Kontrollo Qendrën e veprimeve.' : 'The priorities source did not respond. Check Action Center.';
        }
        if ($view === 'payables') {
            $summary = ($sq ? 'Afate të njohura në shtatë ditë: ' : 'Known due in the next seven days: ').Money::normalize($data['known_due_amount']).' '.$data['currency'].'.';
        }
        if ($view === 'close' && ! $data['period']) {
            $summary = $sq ? 'Nuk u gjet periudhë për shqyrtim. Kontrollo periudhat kontabël.' : 'No eligible period was found. Check accounting periods.';
        }
        if ($view === 'pick' && isset($lead['order_id'])) {
            $url = '/fulfillment?order='.$lead['order_id'];
        }
        if ($view === 'counts' && isset($lead['id'])) {
            $url .= '&count='.$lead['id'];
        }
        if ($view === 'my_tasks' && isset($lead['id'])) {
            $url .= '&task='.$lead['id'];
        }
        if (in_array($view, ['brief', 'bank', 'close', 'late_receipts', 'incoming'], true) && isset($lead['url']) && str_starts_with($lead['url'], '/') && ! str_starts_with($lead['url'], '//')) {
            $url = $lead['url'];
        }
        return ['tool' => $tool, 'title' => $title, 'url' => $url, 'summary' => $summary, 'metrics' => [], 'has_work' => $count > 0, 'source_unavailable' => $sourceUnavailable];
    }

    public function orderBlocker(array $input, array $context, string $conversation, bool $sq, string $operation = 'blocker'): array
    {
        $runner = app(AssistantToolRunner::class);
        if (! $runner->allowed('get_sales_order')) {
            return $this->answer($conversation, 'blocked', $sq ? 'Kjo llogari nuk ka qasje në porositë e depos.' : 'This account cannot view fulfillment orders.', [], [], 'permission_denied');
        }
        $entity = $input['entity'] ?? $context['entity'] ?? null;
        if (! isset($input['entity']) && ($entity['type'] ?? null) !== 'sales_order' && isset($context['fulfillment_order_id'])) {
            $entity = ['type' => 'sales_order', 'id' => $context['fulfillment_order_id']];
        }
        if (preg_match('/\bSO-[a-z0-9-]+\b/i', $input['question'], $m)) {
            $resolved = app(AssistantEntityResolver::class)->resolve($m[0], 'sales_order');
            $entity = $resolved['fuzzy'] ? null : $resolved['entity'];
        }
        if (($entity['type'] ?? null) !== 'sales_order') {
            return $this->answer($conversation, 'blocked', $sq ? 'Cila porosi nuk po niset? Shkruaj numrin e porosisë ose pyet nga vetë porosia.' : 'Which order is blocked? Enter its order number or ask from the order page.', [], [], 'needs_clarification');
        }
        $order = $runner->execute('get_sales_order', ['sales_order_id' => (int) $entity['id']]);
        $url = '/fulfillment?order='.$entity['id'];
        $missing = collect($order['items'])->groupBy('product_id')->map(function ($lines, $id) use ($order, $url, $sq) {
            $quantity = round($lines->sum('base_quantity') - $lines->sum('reserved_quantity') - $lines->sum('dispatched_quantity'), 3);
            $product = $lines->first()['product'] ?? null;
            if ($quantity <= 0 || ! $product) {
                return null;
            }
            $stock = $order['availability'][$id] ?? [];
            $metrics = [['label' => $sq ? 'Pa rezervim' : 'Not reserved', 'value' => $quantity, 'unit' => $product['unit']]];
            if (isset($stock['available'])) {
                $metrics[] = ['label' => $sq ? 'Stok i lirë në këtë shtrirje' : 'Available in this stock scope',
                    'value' => $stock['available'], 'unit' => $product['unit']];
            }
            return ['tool' => 'get_sales_order', 'title' => $product['name'], 'url' => $url, 'metrics' => $metrics,
                'entity' => ['type' => 'product', 'id' => (int) $id], 'stock' => $stock, 'quantity' => $quantity];
        })->filter()->values();
        $items = $missing->take(5)->map(fn ($item) => array_diff_key($item, ['stock' => true, 'quantity' => true]))->all();
        $entity = ['type' => 'sales_order', 'id' => (int) $entity['id'], 'title' => $order['order_number']];
        [$status, $action] = $this->orderReview($order, $missing, $sq);
        $topic = $operation === 'blocker' ? 'blocked' : 'order_'.$operation;
        if ($operation === 'shortage') {
            $status = $missing->isEmpty()
                ? ($sq ? 'Nuk ka sasi të parezervuar në këtë pamje.' : 'No unreserved quantity appears in this view.')
                : ($sq ? 'Disa artikuj kanë sasi pa rezervim.' : 'Some items have quantities that are not reserved.');
        }
        $card = ['tool' => 'get_sales_order', 'title' => $order['order_number'], 'url' => $url, 'metrics' => []];
        $reply = $this->answer($conversation, $topic, $order['order_number'].' · '.$status,
            [$card, ...$items], $runner->executions, 'success');
        $reply['meaning'] = $sq
            ? 'Sasia e parezervuar, vetë, nuk provon pse nuk është nisur porosia.'
            : 'An unreserved quantity alone does not establish why dispatch has not happened.';
        $reply['next_action'] = ['url' => $url, 'label' => $action];
        $reply['fulfillment_reference'] = $entity['id'];
        $reply['context'] = $operation === 'shortage' && $missing->count() === 1 && $runner->allowed('get_product_availability')
            ? $items[0]['entity'] + ['title' => $items[0]['title']] : $entity;
        $reply['business_intent'] = [
            'domain' => 'fulfillment', 'operation' => $operation,
            'metric' => $operation === 'shortage' ? 'unreserved_quantity' : 'order_progress',
            'entity' => $entity, 'scope' => 'authorized_company', 'warehouse_id' => $order['warehouse_id'],
            'evidence_tool' => 'get_sales_order', 'required_permissions' => ['fulfillment.view'],
        ];
        return $reply;
    }

    private function orderReview(array $order, \Illuminate\Support\Collection $missing, bool $sq): array
    {
        $can = fn (string $name) => app(PermissionService::class)->roleHasPermission(auth()->user()->role, 'fulfillment.'.$name);
        $active = collect($order['allocations'])->where('status', '!=', 'released');
        $openPackage = collect($order['packages'])->contains(fn ($package) => empty($package['outbound_dispatch_id']));
        $shortStock = $missing->filter(fn ($item) => isset($item['stock']['available']) && (float) $item['stock']['available'] < $item['quantity']);
        $incoming = $shortStock->isNotEmpty() && $shortStock->every(fn ($item) => ! empty($item['stock']['incoming_coverage']));
        $open = $sq ? 'Hap porosinë' : 'Open order';
        $supervisor = $sq ? 'Shqyrto porosinë me përgjegjësin' : 'Review the order with a supervisor';
        return match (true) {
            $order['status'] === 'cancelled' => [$sq ? 'Porosia është anuluar.' : 'The order is cancelled.', $open],
            $order['status'] === 'delivered' => [$sq ? 'Dorëzimi është regjistruar.' : 'Delivery is recorded.', $open],
            $order['status'] === 'dispatched' => [$sq ? 'Nisja është regjistruar.' : 'Dispatch is recorded.', $sq ? 'Shqyrto dorëzimin' : 'Review delivery'],
            ! empty($order['approval']) && ($order['approval']['status'] ?? null) === 'pending' =>
                [$sq ? 'Ka një miratim në pritje për këtë porosi.' : 'This order has a pending approval.', $supervisor],
            empty($order['confirmed_at']) => [$sq ? 'Porosia ende nuk është konfirmuar.' : 'The order is awaiting confirmation.', $supervisor],
            collect($order['tasks'])->contains('status', 'exception') =>
                [$sq ? 'Një detyrë mbledhjeje ka përjashtim të regjistruar.' : 'A picking task has a recorded exception.', $supervisor],
            $openPackage => [$sq ? 'Ka pako të përgatitura që ende nuk janë nisur.' : 'Packed items are awaiting dispatch.',
                $can('dispatch') ? ($sq ? 'Shqyrto pakot për nisje' : 'Review packages for dispatch') : $supervisor],
            $active->contains(fn ($row) => (float) $row['picked_quantity'] > (float) $row['packed_quantity']) =>
                [$sq ? 'Ka artikuj të mbledhur për paketim.' : 'Picked items still need packing.',
                    $can('pick') ? ($sq ? 'Shqyrto paketimin' : 'Review packing') : $supervisor],
            $active->contains(fn ($row) => (float) $row['quantity'] > (float) $row['picked_quantity']) =>
                [$sq ? 'Ka sasi të alokuara për t’u mbledhur.' : 'Allocated quantities still need picking.',
                    $can('pick') ? ($sq ? 'Shqyrto mbledhjen' : 'Review picking') : $supervisor],
            $incoming => [$sq ? 'Stoku i lirë nuk mjafton. Pranimet e pritshme mund ta mbulojnë sasinë.' : 'Available stock is short. Expected receipts may cover the quantity.', $supervisor],
            $shortStock->isNotEmpty() => [$sq ? 'Stoku i lirë nuk mjafton për sasitë e parezervuara në këtë shtrirje.' : 'Available stock in this scope does not cover the unreserved quantities.', $supervisor],
            $missing->isNotEmpty() => [$sq ? 'Ka sasi që ende nuk janë rezervuar.' : 'Some quantities are still unreserved.',
                $can('manage') ? ($sq ? 'Shqyrto rezervimet' : 'Review reservations') : $supervisor],
            default => [$sq ? 'Nisja ende nuk është konfirmuar. Burimi nuk tregon një shkak të qartë.' : 'Dispatch is unconfirmed. The record does not establish a cause.', $supervisor],
        };
    }

    private function answer(string $conversation, string $topic, string $text, array $cards, array $tools, string $state): array
    {
        $lead = $cards[0] ?? null;
        return [
            'id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => 'work_'.$topic,
            'text' => $text, 'cards' => $cards, 'sources' => array_map(fn ($card) => ['tool' => $card['tool'], 'url' => $card['url']], $cards),
            'next_action' => $lead ? ['url' => $lead['url'], 'label' => $lead['title']] : null,
            'limitations' => [], 'choices' => [], 'read_only' => true, 'state' => $state, 'provider' => 'deterministic', 'tools' => $tools,
        ];
    }

    private function withProposal(array $reply, ?array $proposal): array
    {
        if ($proposal) {
            $reply['provider'] = 'ollama';
            $reply['model'] = $proposal['model'];
            if (config('assistant.diagnostics')) {
                $reply['model_timing'] = $proposal['timing'];
            }
        }
        app(AnalyticsDataService::class)->audit('assistant.query', [
            'response_id' => $reply['id'], 'intent' => $reply['intent'], 'provider' => $reply['provider'],
            'tools' => array_column($reply['tools'], 'tool'), 'response_state' => $reply['state'],
        ]);
        return $reply;
    }
}
