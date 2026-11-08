<?php

namespace App\Services;

use App\Support\CompanyClock;
use Illuminate\Support\Str;

/** The model may suggest a route. AIMS owns the metric, scope, permissions and evidence. */
final class AssistantBusinessIntent
{
    public function fulfillmentOperation(string $question, bool $hasOrder): ?string
    {
        $q = app(AssistantLanguage::class)->normalize($question);
        if (preg_match('/\b(?:supplier\w*|vendor\w*|furnitor\w*|purchase order|payroll|what if|nese|simulo|simulate|other compan\w*|another company|ignore.*instructions)\b/', $q)) {
            return null;
        }
        $order = $hasOrder || preg_match('/\b(?:orders?|porosi\w*)\b/', $q);
        $movement = preg_match('/\b(?:dispatch\w*|shipp?\w*|going out|go out|leav\w*|nis\w*|dal\w*|del)\b/', $q);
        $obstacle = preg_match('/\b(?:why|pse|prevent\w*|stop\w*|keep\w*|block\w*|stuck|cannot|can.?t|not|nuk|spo|peng\w*|bllok\w*)\b/', $q);
        if ($order && $movement && $obstacle) {
            return 'blocker';
        }
        if (! $hasOrder) {
            return null;
        }
        if (preg_match('/\b(?:missing|short|unreserved|unallocated|mung\w*|parezerv\w*)\b/', $q)
            && (preg_match('/\b(?:product|items?|artikull\w*|reservation|rezerv\w*|orders?|porosi\w*)\b/', $q)
                || preg_match('/^(?:what(?:s| is)|whats still|cka|what) (?:missing|mungon)[?!. ]*$/', $q))
            && ! preg_match('/\b(?:warehouse|depo\w*|customer|klient\w*|cash|bank\w*|reorder|replenish)\b/', $q)) {
            return 'shortage';
        }
        if (preg_match('/\b(?:fulfill\w*|progress|perparim\w*|ecur\w*)\b/', $q) && ($movement || preg_match('/\b(?:orders?|porosi\w*|warehouse|depo\w*|fulfill\w*)\b/', $q))) {
            return 'progress';
        }
        return null;
    }

    public function isNextStep(string $question): bool
    {
        $q = app(AssistantLanguage::class)->normalize($question);
        return (bool) preg_match('/^(?:and )?(?:what (?:should|can|do) (?:i|we) (?:do|check|review|open) (?:next|now)|what next|(?:cka|what) (?:te|should te|me|should me) (?:bej|do|kontrollo(?:j|jme)?|shqyrt(?:oj|ojme|u|uar)|review|hap(?:im)?)(?: tani| now| me pas)?)[?!. ]*$/', $q);
    }

    private const ROUTES = [
        'dispatch' => ['fulfillment', 'explain_blocker', 'order_progress', 'get_sales_order', 'fulfillment.view'],
        'receipts' => ['purchasing', 'review_late_receipts', 'outstanding_receipt_quantity', 'get_incoming_supply', 'purchase_orders.view'],
        'payables' => ['accounting', 'review_bills_due', 'dated_supplier_payables', 'get_accounting_payables', 'finance.view'],
        'receivables' => ['accounting', 'rank_debtors', 'outstanding_customer_receivables', 'get_accounting_receivables', 'debts.view'],
    ];

    public function reply(array $input, string $conversation, array $context): ?array
    {
        $question = $input['question'];
        $q = app(AssistantLanguage::class)->normalize($question);
        if (isset($input['simulation_id']) || preg_match('/\b(?:what if|po nese|simulate|ignore.*instructions|system prompt|another company|other compan\w*|tenant|fshi|delete|post|execute|payroll|tatim\w*|vat|tvsh)\b/', $q)) {
            return null;
        }

        $customer = preg_match('/\b(?:customers?|clients?|klient\w*|debtors?)\b/', $q);
        $vendor = preg_match('/\b(?:suppliers?|vendors?|furnitor\w*)\b/', $q);
        $bill = preg_match('/\b(?:bills?|invoices?|payments?|fatur\w*|pages\w*|pagese|payables?)\b/', $q);
        $inbound = preg_match('/\b(?:receipts?|received|deliveries|arriv\w*|inbound|pranim\w*|mall\w*|ardh\w*)\b/', $q);
        $late = preg_match('/\b(?:late|overdue|past (?:its |the )?(?:due|promised)|already be here|not received|vonu\w*|kalu\w* afat\w*|kalu\w*.*(?:date|daten|afat\w*)|dashte? me (?:mberri|ardh))\b/', $q);
        $due = preg_match('/\b(?:due|next week|this week|next \d+ days|seven days|shtate dite\w*|\d+ dite\w*|kete jave|afat\w*)\b/', $q);
        $dispatch = preg_match('/\b(?:dispatch\w*|ship(?:ping|ment)?|nis\w*|order.*(?:del|ship)|del.*(?:order|warehouse))\b/', $q);
        $blocked = preg_match('/\b(?:why|pse|prevent\w*|block\w*|stuck|cant|cannot|spo|nuk|nal\w*|bllok\w*)\b/', $q);
        $debt = preg_match('/\b(?:owe\w*|debt\w*|balances?|borxh\w*|detyro\w*)\b/', $q);
        $rank = preg_match('/\b(?:rank|biggest|largest|top|most|highest|shum\w*|(?:ma|me)(?: se)? (?:much|t?madh)|debtors?)\b/', $q);
        $sales = preg_match('/\b(?:sales|sold|bought|buy|purchas\w*|shitj\w*)\b/', $q);
        $historicalSupplier = preg_match('/\b(?:keeps?|often|repeated\w*|history|histor\w*|shpesh|gjithmone)\b/', $q);
        $orderLeaving = preg_match('/\borders?\b.*\b(?:going out|leaving)\b/', $q);
        $outbound = ! $vendor && ($dispatch || $orderLeaving || preg_match('/\bSO-[a-z0-9-]+\b/i', $question));
        $orderFollowup = ($context['last_intent'] ?? null) === 'work_blocked' && preg_match('/^\s*(?:and |what about |po )?SO-[a-z0-9-]+[?.! ]*$/i', $question);
        $signals = [
            'dispatch' => $outbound && ($blocked || $orderLeaving && preg_match('/\b(?:keep\w*|stop\w*|hold\w*)\b/', $q)) || $orderFollowup,
            'receipts' => ! $customer && ! $bill && ! $historicalSupplier && $late
                && ($inbound && ($vendor || preg_match('/\b(?:receipts?|received|arrival|inbound|pranim\w*|mall\w*|ardh\w*)\b/', $q)) || $vendor && str_contains($q, 'incoming')),
            'payables' => ! $customer && $bill && $due && ($vendor || preg_match('/\b(?:bills?|payables?)\b/', $q)),
            'receivables' => $customer && $debt && $rank && ! $sales,
        ];
        $certain = array_keys(array_filter($signals));
        $sq = ($input['language'] ?? 'en') === 'sq';
        if (! $certain && $inbound && ! $late && ! $customer && ! $bill && ! isset($input['entity'])
            && preg_match('/\b(?:this week|kete jave)\b/', $q)
            && ! preg_match('/\b(?:only|vetem|from|prej|nga|which warehouse|ne warehouse|sales orders?)\b|\bPO-|["“]/i', $q)
            && app(AssistantToolRunner::class)->allowed('get_incoming_supply')) {
            return app(InventoryAssistantFacts::class)->reply('incoming_supply', $conversation, $sq,
                ['through' => CompanyClock::today()->endOfWeek()->toDateString()]);
        }
        $receiptNoun = preg_match('/\b(?:receipts?|deliveries|goods|pranim\w*|mall\w*)\b/', $q);
        if (count($certain) > 1 || $bill && $receiptNoun && $vendor && $due && $late || $customer && $debt && $rank && $sales) {
            return $this->limited($conversation, $sq, 'needs_clarification');
        }

        // Offer only categories supported by independent words in the question.
        $candidates = array_keys(array_filter([
            'dispatch' => $outbound && (bool) preg_match('/\b(?:issue|problem|hold|status|problem\w*|penges\w*)\b/', $q),
            'receipts' => $vendor && $inbound && ! $customer && ! $bill && ! $historicalSupplier && preg_match('/\b(?:waiting|held up|missing|should have|pret\w*|ngec\w*)\b/', $q),
            'payables' => $vendor && $bill && $due,
        ]));
        if (! $certain && ! $candidates) {
            return null;
        }
        $actor = auth()->user();
        $proposal = $certain ? null : app(LocalAssistantProvider::class)->businessIntent($question, $candidates);
        $route = $certain[0] ?? $proposal['route'] ?? null;
        if (! $route || ($proposal['ambiguous'] ?? false)) {
            return $this->limited($conversation, $sq, 'needs_clarification');
        }
        if ($proposal) {
            $current = $actor->fresh(['company']);
            if (! $current || (int) $current->company_id !== (int) $actor->company_id) {
                return $this->limited($conversation, $sq, 'permission_denied');
            }
            \Illuminate\Support\Facades\Auth::setUser($current);
        }
        [$domain, $operation, $metric, $tool, $permission] = self::ROUTES[$route];
        $runner = app(AssistantToolRunner::class);
        if (! $runner->allowed($tool)
            || $route === 'payables' && ! app(PermissionService::class)->roleHasPermission(auth()->user()->role, 'accounting.reports.view')) {
            return $this->limited($conversation, $sq, 'permission_denied');
        }
        if ($route !== 'dispatch' && (isset($input['entity']) || isset($input['customer_id'])
            || preg_match('/["“]|\b(?:only|vetem|last month|last year|yesterday|dje|last week|20\d{2}-\d{2}|PO-[a-z0-9-]+)\b/i', $question)
            || preg_match('/\b(?:in|at|ne|per) (?:warehouse|depo)\b/', $q)
            || $route === 'payables' && preg_match('/\b(?!7\b)\d+\s*(?:days?|dite\w*)\b|\bnext (?!seven\b)[a-z]+ days\b|\b(?:today|tomorrow|neser|next month|past due|overdue)\b/', $q))) {
            return $this->limited($conversation, $sq, 'insufficient');
        }
        $today = CompanyClock::today();
        $through = $route === 'payables' && preg_match('/\b(?:this week|kete jave)\b/', $q)
            ? $today->endOfWeek()->toDateString() : $today->addDays(7)->toDateString();
        $reply = match ($route) {
            'dispatch' => app(AssistantWorkGuidance::class)->orderBlocker($input, $context, $conversation, $sq),
            'receipts' => app(OperatingAssistantFacts::class)->overdueReceipts($runner, $conversation, $sq),
            'payables' => app(FinanceAssistantFacts::class)->reply('finance_payables', $question, $conversation, $sq, false, $through),
            'receivables' => app(FinanceAssistantFacts::class)->reply('finance_receivables', $question, $conversation, $sq, rankCustomers: true),
        };

        // Validate executed evidence, not a tool name proposed by the model.
        if (($reply['state'] ?? null) === 'success') {
            $calls = $reply['tools'] ?? [];
            $args = $calls[0]['arguments'] ?? [];
            if (count($calls) !== 1 || ($calls[0]['tool'] ?? null) !== $tool || ($calls[0]['status'] ?? null) !== 'success'
                || $route === 'receipts' && ($args['window'] ?? null) !== 'overdue'
                || $route === 'payables' && ($args['due_through'] ?? null) !== $through
                || $route === 'dispatch' && ($args['sales_order_id'] ?? null) !== (int) ($reply['context']['id'] ?? 0)) {
                return $this->limited($conversation, $sq, 'insufficient');
            }
        }
        $reply['business_intent'] = [
            'domain' => $domain, 'operation' => $operation, 'metric' => $metric,
            'entity' => $route === 'dispatch' ? ($reply['context'] ?? null) : null,
            'period' => match ($route) {
                'payables' => ['from' => $today->toDateString(), 'through' => $through],
                'receipts' => ['before' => $today->toDateString()], default => ['as_of' => $today->toDateString()],
            },
            'scope' => 'authorized_company', 'conversation_reference' => (bool) $orderFollowup,
            'evidence_tool' => $tool, 'required_permissions' => $route === 'payables' ? [$permission, 'accounting.reports.view'] : [$permission],
            'interpretation' => $proposal ? 'schema_proposal' : 'clear_request',
            'ambiguous' => ($reply['state'] ?? null) === 'needs_clarification',
        ];
        if ($proposal) {
            $reply['provider'] = 'ollama';
        }
        return $reply;
    }

    private function limited(string $conversation, bool $sq, string $state): array
    {
        $text = match ($state) {
            'permission_denied' => $sq ? 'Kjo llogari nuk ka qasje në këtë shqyrtim.' : 'This account cannot access this review.',
            'insufficient' => $sq ? 'Nuk mund ta verifikoj këtë filtër ose periudhë me burimin e disponueshëm.' : 'I cannot verify that filter or period with the available source.',
            default => $sq ? 'Cilin shqyrtim kërkon? Përcakto porosinë, pranimin ose detyrimin që do të kontrollosh.' : 'Which review do you need? Specify the order, receipt or balance you want to check.',
        };
        return ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => 'business_review',
            'text' => $text, 'state' => $state, 'provider' => 'deterministic', 'read_only' => true,
            'tools' => [], 'cards' => [], 'sources' => [], 'choices' => [], 'limitations' => []];
    }
}
