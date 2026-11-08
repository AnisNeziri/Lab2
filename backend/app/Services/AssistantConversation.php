<?php
namespace App\Services;

use Carbon\CarbonImmutable;
use App\Support\{CompanyClock, CompanyCurrency, Money};
use Illuminate\Support\Str;

/** Remembers query parameters, never answers or financial balances. */
final class AssistantConversation
{
    public function historicalVesselPosition(array $input, string $conversation): ?array
    {
        $q = app(AssistantLanguage::class)->normalize($input['question']);
        if (isset($input['simulation_id']) || ! preg_match('/\b(?:vessel|ship|shipment|anij\w*)\b/', $q)
            || ! preg_match('/\b(?:position|coordinates?|vendndodh\w*|pozicion\w*|koordinat\w*)\b/', $q)
            || ! preg_match('/\b(?:yesterday|last (?:night|week|month)|previous|histor\w*|te kaluar)\b|\b20\d{2}-\d{2}-\d{2}\b/', $q)) {
            return null;
        }
        $sq = ($input['language'] ?? 'en') === 'sq';
        $allowed = app(AssistantToolRunner::class)->allowed('get_shipment_intelligence');
        return ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => 'shipments',
            'state' => $allowed ? 'insufficient' : 'permission_denied', 'provider' => 'deterministic', 'read_only' => true,
            'text' => $allowed
                ? ($sq ? 'Ask AIMS nuk mund të verifikojë pozicionin e anijes për atë datë. Hap dërgesën për të kontrolluar gjurmimin e regjistruar.' : 'Ask AIMS cannot verify the vessel’s position for that date. Open the shipment to check its recorded tracking.')
                : ($sq ? 'Roli yt nuk ka leje për të dhënat e gjurmimit të dërgesave.' : 'Your role cannot view shipment tracking information.'),
            'next_action' => $allowed ? ['url' => '/shipments/my-shipments', 'label' => $sq ? 'Hap dërgesat' : 'Open shipments'] : null,
            'cards' => [], 'sources' => [], 'tools' => [], 'choices' => []];
    }

    public function fulfillmentFollowup(array $input, string $conversation, array $context): ?array
    {
        if (isset($input['simulation_id']) || isset($input['customer_id'])
            || isset($input['entity']) && $input['entity']['type'] !== 'sales_order') {
            return null;
        }
        $intent = app(AssistantBusinessIntent::class);
        $question = $input['question'];
        $q = app(AssistantLanguage::class)->normalize($question);
        $guide = app(AssistantWorkGuidance::class);
        $sq = ($input['language'] ?? 'en') === 'sq';
        $hasOrder = isset($context['fulfillment_order_id']);
        $orderNumber = preg_match('/\bSO-[a-z0-9-]+\b/i', $question);
        if ($operation = $intent->fulfillmentOperation($question, $hasOrder || isset($input['entity']) || $orderNumber)) {
            return $guide->orderBlocker($input, $context, $conversation, $sq, $operation);
        }
        if ($orderNumber && preg_match('/^(?:(?:and|po|what about) )?SO-[a-z0-9-]+[?!. ]*$/i', $question)) {
            return $guide->orderBlocker($input, $context, $conversation, $sq, empty($context['awaiting_order']) ? 'progress' : 'blocker');
        }
        if ($hasOrder && $intent->isNextStep($question)) {
            return $guide->orderBlocker($input, $context, $conversation, $sq, 'next');
        }
        if ($hasOrder && ! isset($input['entity']) && ! preg_match('/[\d"“]/u', $question)
            && preg_match('/\b(?:which|where|another|other|cil\w*|ku|tjeter)\b/', $q)
            && preg_match('/\b(?:warehouse|depo\w*)\b/', $q)
            && preg_match('/\b(?:it|that|this|the (?:item|product)|at[eo]|ai|aj[oa]|artikullin|produktin)\b/', $q)) {
            if ($reply = $this->warehouseComparison($input, $conversation, $context, true)) {
                $reply['fulfillment_reference'] = $context['fulfillment_order_id'];
                return $reply;
            }
            if (($context['last_intent'] ?? null) === 'work_order_shortage') {
                return ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => 'warehouse_comparison',
                    'state' => 'needs_clarification', 'read_only' => true, 'provider' => 'deterministic',
                    'text' => $sq ? 'Për cilin artikull do të kontrollosh depot?' : 'Which item should I check across warehouses?',
                    'cards' => [], 'sources' => [], 'tools' => [], 'choices' => [],
                    'fulfillment_reference' => $context['fulfillment_order_id']];
            }
        }
        return null;
    }

    public function purchaseOrderReview(array $input, string $conversation, array $context = []): ?array
    {
        $question = $input['question'];
        $q = app(AssistantLanguage::class)->normalize($question);
        if (isset($input['simulation_id']) || preg_match('/\b(?:what if|nese|simulate|compare|krahas\w*|ignore.*instructions)\b/', $q)) {
            return null;
        }
        $intent = app(AssistantBusinessIntent::class);
        $reference = preg_match('/\bPO-[a-z0-9-]+\b/i', $question, $match);
        $next = ($context['last_intent'] ?? null) === 'purchase_order_review' && $intent->isNextStep($question);
        if (! $next && (! $reference || ! preg_match('/\b(?:status|receipts?|received|delivery|supplier|pranim\w*|gjend\w*|furnitor\w*)\b/', $q)
            || preg_match('/^(?:confirm|approve|receive|cancel|pay|konfirmo|mirato|prano|anulo|paguaj)\b/', $q))) {
            return null;
        }
        $sq = ($input['language'] ?? 'en') === 'sq';
        $base = ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => 'purchase_order_review',
            'provider' => 'deterministic', 'read_only' => true, 'cards' => [], 'sources' => [], 'tools' => [], 'choices' => []];
        $runner = app(AssistantToolRunner::class);
        if (! $runner->allowed('get_purchase_order')) {
            return $base + ['state' => 'permission_denied', 'text' => $sq ? 'Roli yt nuk ka leje për porositë e blerjes.' : 'Your role cannot view purchase orders.'];
        }
        $resolved = $reference ? app(AssistantEntityResolver::class)->resolve($match[0], 'purchase_order') : null;
        $entity = $reference ? ($resolved['fuzzy'] ? null : $resolved['entity']) : ($context['entity'] ?? null);
        if (($entity['type'] ?? null) !== 'purchase_order') {
            return $base + ['state' => 'needs_clarification', 'text' => $sq
                ? 'Nuk gjeta një porosi të lejuar me atë numër. Kontrollo referencën e porosisë.'
                : 'I could not find a purchase order you can view with that number. Check the order reference.'];
        }
        $data = $runner->execute('get_purchase_order', ['purchase_order_id' => (int) $entity['id']]);
        $status = match ($data['status']) {
            'received' => $sq ? 'E shënuar si e pranuar' : 'Marked received',
            'partially_received' => $sq ? 'Pjesërisht e pranuar' : 'Partially received',
            'cancelled' => $sq ? 'E anuluar' : 'Cancelled',
            default => $sq ? 'Pranimi i plotë nuk është regjistruar' : 'Full receipt is not recorded',
        };
        $url = '/purchase-orders?po='.$entity['id'];
        $text = $data['po_number'].' · '.$status.'. '.($sq ? 'Dokumente pranimi: ' : 'Receipt records: ').$data['receipts_count'].'.';
        $meaning = $sq ? 'Hap porosinë për datat dhe sasitë e regjistruara të pranimit.' : 'Open the order to review recorded receipt dates and quantities.';
        return array_replace($base, ['state' => 'success', 'text' => $text, 'meaning' => $meaning,
            'cards' => [['tool' => 'get_purchase_order', 'title' => $data['po_number'], 'summary' => $data['supplier']['name'] ?? null, 'url' => $url,
                'metrics' => [['label' => $sq ? 'Dokumente pranimi' : 'Receipt records', 'value' => $data['receipts_count']]]]],
            'sources' => [['tool' => 'get_purchase_order', 'url' => $url]], 'tools' => $runner->executions,
            'context' => $entity + ['title' => $data['po_number']],
            'next_action' => ['url' => $url, 'label' => $sq ? 'Hap porosinë dhe pranimet' : 'Open order and receipts']]);
    }

    public function sourceReview(array $input, string $conversation, array $context): ?array
    {
        if (isset($input['entity']) || isset($input['customer_id']) || isset($input['simulation_id'])
            || ! app(AssistantBusinessIntent::class)->isNextStep($input['question'])) {
            return null;
        }
        $sq = ($input['language'] ?? 'en') === 'sq';
        $last = $context['last_intent'] ?? '';
        $runner = app(AssistantToolRunner::class);
        $finance = app(FinanceAssistantFacts::class);
        $reply = match ($last) {
            'selected_customer', 'customer_payment_activity' => ($context['entity']['type'] ?? null) === 'customer'
                ? $this->businessFollowup(['question' => 'Have they paid recently?', 'language' => $input['language'] ?? 'en',
                    'entity' => $context['entity']], $conversation, $context) : null,
            'finance_payables' => ! empty($context['payables_through']) && $context['payables_through'] >= CompanyClock::today()->toDateString()
                ? $finance->reply($last, $input['question'], $conversation, $sq, dueThrough: $context['payables_through']) : null,
            'finance_bank_reconciliation', 'finance_reconciliation' =>
                $finance->reply($last, $input['question'], $conversation, $sq),
            'finance_close' => ! empty($context['close_period'])
                ? $finance->reply($last, implode(' ', $context['close_period']), $conversation, $sq) : null,
            'overdue_supply', 'work_receipts' => $runner->allowed('get_incoming_supply')
                ? app(OperatingAssistantFacts::class)->overdueReceipts($runner, $conversation, $sq) : null,
            'incoming_supply' => app(InventoryAssistantFacts::class)->reply($last, $conversation, $sq,
                isset($context['receipt_through']) && $context['receipt_through'] >= CompanyClock::today()->toDateString()
                    ? ['through' => $context['receipt_through']] : []),
            'work_day', 'brief' => app(AssistantWorkGuidance::class)->reply(['question' => 'What should I work on today?',
                'language' => $input['language'] ?? 'en'], $conversation, []),
            'crm_review' => ($context['review_tool'] ?? null) === 'get_quotation_followups'
                ? app(CustomerSalesAssistantFacts::class)->reply(['question' => 'Which quotations need follow-up?',
                    'language' => $input['language'] ?? 'en'], $conversation) : null,
            default => null,
        };
        if ($reply && empty($reply['next_action']) && ! empty($reply['cards'][0]['url'])) {
            $reply['next_action'] = ['url' => $reply['cards'][0]['url'],
                'label' => $sq ? 'Hap burimin për shqyrtim' : 'Open source for review'];
        }
        return $reply;
    }

    public function businessFollowup(array $input, string $conversation, array $context): ?array
    {
        $q = app(AssistantLanguage::class)->normalize($input['question']);
        $sq = ($input['language'] ?? 'en') === 'sq';
        $last = $context['last_intent'] ?? null;
        $entity = $input['entity'] ?? $context['entity'] ?? null;
        $runner = app(AssistantToolRunner::class);
        $base = ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'read_only' => true,
            'provider' => 'deterministic', 'choices' => [], 'cards' => [], 'sources' => [], 'tools' => []];

        if (in_array($last, ['overdue_supply', 'incoming_supply', 'work_receipts', 'supplier_origin_unverified'], true)
            && preg_match('/\b(?:china|country of origin|shteti i origjines)\b/', $q) && ! isset($input['entity'])) {
            return array_replace($base, ['intent' => 'supplier_origin_unverified', 'state' => 'insufficient',
                'text' => $sq ? 'Burimi i pranimeve nuk konfirmon furnitorin sipas shtetit. Jep emrin ose numrin e porosisë.' : 'The receipt evidence cannot identify a supplier by country. Give the supplier name or purchase order number.',
                'next_action' => $runner->allowed('get_incoming_supply') ? ['url' => '/purchase-orders', 'label' => $sq ? 'Shqyrto porositë' : 'Review Purchase Orders'] : null]);
        }
        if ($last === 'supplier_origin_unverified' && preg_match('/\b(?:owe|debt|payable|detyro\w*)\b/', $q)
            && preg_match('/\b(?:them|that (?:supplier|vendor)|atyre|atij)\b/', $q)) {
            return array_replace($base, ['intent' => 'supplier_origin_unverified', 'state' => 'needs_clarification',
                'text' => $sq ? 'Cilin furnitor nënkupton? Jep emrin e tij.' : 'Which supplier do you mean? Give the supplier name.']);
        }
        if (! isset($input['entity']) && ($selected = $this->selected($input['question'], $context))
            && $selected['type'] === 'customer' && $runner->allowed('get_customer')) {
            $data = $runner->execute('get_customer', ['customer_id' => (int) $selected['id']]);
            $view = app(AssistantComposer::class)->compose([['tool' => 'get_customer', 'data' => $data]], $sq);
            return array_replace($base, $view, ['intent' => 'selected_customer', 'state' => 'success',
                'text' => $selected['title'], 'context' => $selected, 'tools' => $runner->executions]);
        }
        if (preg_match('/\b(?:they|them|this customer|that customer|ata|atyre|ky (?:klient|customer))\b/', $q)
            && preg_match('/\b(?:paid|payments?|pagese|pagesa|pagu\w*)\b/', $q)
            && (in_array($last, ['selected_customer', 'customer_payment_activity', 'finance_receivables'], true) || ($entity['type'] ?? null) === 'customer')) {
            if (($entity['type'] ?? null) !== 'customer' || $last === 'finance_receivables' && ! isset($input['entity'])) {
                return array_replace($base, ['intent' => 'customer_payment_activity', 'state' => 'needs_clarification',
                    'text' => $sq ? 'Për cilin klient do të kontrollosh pagesat?' : 'Which customer’s payments should I check?']);
            }
            if (! $runner->allowed('get_customer_payment_activity')) {
                return array_replace($base, ['intent' => 'customer_payment_activity', 'state' => 'permission_denied',
                    'text' => $sq ? 'Kjo llogari nuk ka qasje në pasqyrën e klientit.' : 'This account cannot access the customer statement.']);
            }
            $tool = 'get_customer_payment_activity';
            $data = $runner->execute($tool, ['customer_id' => (int) $entity['id']]);
            $url = app(FinanceAssistantFacts::class)->receivablesUrl((int) $entity['id']);
            $text = $data['customer']['name'].' · '.$data['from'].' → '.$data['to'].' ('.$data['currency'].'). '.($data['rows']
                ? ($sq ? 'Ka regjistrime të pagesave ose anulimeve për shqyrtim.' : 'There are payment or reversal entries to review.')
                : ($sq ? 'Nuk ka regjistrime pagesash në këtë periudhë.' : 'No payment entries appear in this period.'));
            return array_replace($base, ['intent' => 'customer_payment_activity', 'state' => 'success', 'text' => $text,
                'cards' => array_map(fn ($row) => ['tool' => $tool, 'title' => $row->date, 'summary' => $row->type === 'payment_reversal'
                    ? ($sq ? 'Anulim pagese' : 'Payment reversal') : ($sq ? 'Pagesë e regjistruar' : 'Recorded payment'), 'url' => $url, 'metrics' => []], $data['rows']),
                'context' => $entity, 'sources' => [['tool' => $tool, 'url' => $url]],
                'next_action' => ['url' => $url, 'label' => $sq ? 'Shqyrto klientin' : 'Review customer'],
                'limitations' => [$sq ? 'Deri në 10 regjistrime, në monedhën e kompanisë.' : 'Up to 10 entries in company currency.'],
                'tools' => $runner->executions]);
        }
        if (isset($context['payables_through']) && in_array($last, ['finance_payables', 'workforce'], true)
            && preg_match('/\b(?:those|these|ato|keto)\s+(?:bills?|invoices?|fatur\w*)\b/', $q)
            && preg_match('/\b(?:outstanding|unpaid|due|mbetur|paid)\b/', $q)
            && $context['payables_through'] >= CompanyClock::today()->toDateString()
            && $runner->allowed('get_accounting_payables')
            && app(PermissionService::class)->roleHasPermission(auth()->user()->role, 'accounting.reports.view')) {
            return app(FinanceAssistantFacts::class)->reply('finance_payables', $input['question'], $conversation, $sq, dueThrough: $context['payables_through']);
        }

        return null;
    }

    public function salesComparison(array $input, string $conversation): ?array
    {
        if (isset($input['entity'], $input['entity']['id']) || isset($input['customer_id']) || isset($input['simulation_id'])) {
            return null;
        }
        $periods = app(AssistantPlanner::class)->salesComparisonPeriods($input['question'], CompanyClock::timezone());
        if (! $periods) {
            return null;
        }
        $runner = app(AssistantToolRunner::class);
        if (! $runner->allowed('get_sales_analytics')) {
            return null;
        }
        $sq = ($input['language'] ?? 'en') === 'sq';
        $currency = CompanyCurrency::current();
        $cards = $totals = [];
        foreach ($periods as $range) {
            $data = $runner->execute('get_sales_analytics', ['period' => 'custom'] + $range);
            $total = empty($data['trend']) ? null : Money::normalize($data['metrics']['revenue']);
            $totals[] = $total;
            $cards[] = ['tool' => 'get_sales_analytics', 'title' => $range['from'].' → '.$range['to'], 'url' => '/analytics?tab=sales',
                'summary' => $total === null ? ($sq ? 'Nuk ka aktivitet të regjistruar.' : 'No recorded activity.') : null,
                'metrics' => $total === null ? [] : [['label' => $sq ? 'Shitje të regjistruara' : 'Recorded sales', 'value' => $total, 'unit' => $currency]]];
        }
        $text = ($sq ? 'Shitjet e regjistruara: ' : 'Recorded sales: ').implode(' · ', array_map(fn ($range, $total) =>
            substr($range['from'], 0, 7).' '.($total === null ? ($sq ? 'pa aktivitet' : 'no activity') : $total.' '.$currency), $periods, $totals)).'.';
        if (! in_array(null, $totals, true)) {
            $text .= ($sq ? ' Ndryshimi: ' : ' Difference: ').Money::subtract($totals[0], $totals[1]).' '.$currency.'.';
        }
        return ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => 'sales_period_comparison',
            'text' => $text, 'cards' => $cards, 'sources' => [['tool' => 'get_sales_analytics', 'url' => '/analytics?tab=sales']],
            'next_action' => ['url' => '/analytics?tab=sales', 'label' => $sq ? 'Shqyrto shitjet' : 'Review sales'],
            'choices' => [], 'limitations' => [], 'read_only' => true, 'provider' => 'deterministic', 'state' => 'success', 'tools' => $runner->executions];
    }

    public function compareReferences(array $v,string $conversation,array $context):?array
    {
        $q=app(AssistantLanguage::class)->normalize($v['question']);
        if(!preg_match('/compare|krahas|versus|\bvs\b|which.*(?:more|less|higher|lower|ma shum|ma pak|me shume|me pak)/',$q)||!preg_match('/those|these|them|keta|keto|atyre|tyne|two|dy|which one/',$q))return null;
        $entities=array_values(array_filter($context['result_entities']??[],fn($e)=>in_array($e['type']??null,['product','supplier'])));
        if(count($entities)<2)return null;$type=$entities[0]['type'];$entities=array_slice(array_values(array_filter($entities,fn($e)=>$e['type']===$type)),0,2);
        $runner=app(AssistantToolRunner::class);$tool=$type==='product'?'get_product_availability':'get_supplier_performance';$results=[];
        foreach($entities as $entity)$results[]=['tool'=>$tool,'data'=>$runner->execute($tool,[$type.'_id'=>$entity['id']])];
        $sq=($v['language']??'en')==='sq';$reply=app(AssistantComposer::class)->compose($results,$sq);
        $text=$type==='product'?($sq?'Ja sasia e disponueshme për dy produktet e para. Njësitë mbeten veçmas.':'Current availability for the first two products. Units remain separate.'):($sq?'Këto janë vlerësimet aktuale të dy furnitorëve; nuk provojnë cili ishte më i miri në një periudhë historike.':'These are the two current supplier scorecards; they do not prove a historical best supplier.');
        if($type==='product'&&count($results)===2&&$results[0]['data']['product']['unit']===$results[1]['data']['product']['unit']){
            [$a,$b]=array_column($results,'data');if($a['position']['available']<$b['position']['available'])[$a,$b]=[$b,$a];
            $text=$a['product']['name'].': '.$a['position']['available'].' '.$a['product']['unit'].'; '.$b['product']['name'].': '.$b['position']['available'].' '.$b['product']['unit'].'. '.($sq?'Krahasim i stokut aktual.':'Current available stock comparison.');
        }
        return $reply+['id'=>(string)\Illuminate\Support\Str::uuid(),'conversation_id'=>$conversation,'intent'=>'reference_comparison','text'=>$text,'context_entities'=>$entities,'context'=>null,'choices'=>[],'state'=>'success','read_only'=>true,'provider'=>'deterministic','tools'=>$runner->executions];
    }
    public function productExplanation(array $v,string $conversation,array $context):?array
    {
        $q=app(AssistantLanguage::class)->normalize($v['question']);
        $implicit = (bool) preg_match('/(?:what|which).*(?:keep|prevent|limit|block).*(?:free|available)|why.*(?:that|it|rest).*(?:unavailable|available|free)/', $q)
            || (($context['entity']['type'] ?? null) === 'product' && ! isset($v['entity'])
                && ! preg_match('/[\d"“]/u', $v['question'])
                && preg_match('/\b(?:pjes\w* (?:tjeter|e mbetur)|sasia e mbetur|ajo sasi)\b/', $q)
                && preg_match('/\b(?:e lire|disponuesh\w*|rezervu\w*|blloku\w*)\b/', $q));
        if(!$implicit&&!preg_match('/why|pse|explain|shpjego/',$q))return null;
        $entity=$v['entity']??$context['entity']??null;$hint=app(AssistantPlanner::class)->plan($v['question'],$context);
        if(!$implicit&&!empty($hint['term'])){
            $resolved=app(AssistantEntityResolver::class)->resolve($hint['term'],'product');
            if(!$resolved['entity'])return null;$entity=$resolved['entity'];
        }
        if(($entity['type']??null)!=='product')return null;
        $runner=app(AssistantToolRunner::class);$data=$runner->execute('get_product_availability',['product_id'=>$entity['id']]);$p=$data['position'];$unit=$data['product']['unit'];$sq=($v['language']??'en')==='sq';
        $text=$data['product']['name'].' · '.($sq?'Në dispozicion: ':'Available: ').$p['available'].' '.$unit.'. '.($sq?'Të rezervuara: ':'Reserved: ').$p['reserved'].' '.$unit.'; '.($sq?'në përgatitje: ':'picking: ').$p['allocated'].' '.$unit.'.';
        if ((float) $p['physical'] === (float) $p['available']) {
            $text .= $sq ? ' Sasia fizike dhe ajo e lirë përputhen tani.' : ' Physical and available stock match right now.';
        }
        $reply=app(AssistantComposer::class)->compose([['tool'=>'get_product_availability','data'=>$data]],$sq);
        $meaning = (float) $p['physical'] === (float) $p['available']
            ? ($sq ? 'Sasia fizike dhe ajo e lirë përputhen tani. Për ndryshime më të hershme, hap lëvizjet.' : 'Physical and available stock match now. Check movements to investigate earlier changes.')
            : ($sq ? 'Shqyrto rezervimet dhe stokun e bllokuar te burimi.' : 'Review reservations and held stock in the source.');
        return $reply+['id'=>(string)\Illuminate\Support\Str::uuid(),'conversation_id'=>$conversation,'intent'=>'availability_explanation','text'=>$text,'meaning'=>$meaning,'next_action'=>['url'=>'/stock?product='.$entity['id'],'label'=>$sq?'Shqyrto lëvizjet e produktit':'Review product movements'],'context'=>$entity,'context_entities'=>[$entity],'choices'=>[],'read_only'=>true,'provider'=>'deterministic','state'=>'success','tools'=>$runner->executions];
    }
    public function selected(string $question,array $context):?array
    {
        $q=app(AssistantLanguage::class)->normalize($question);
        if(!preg_match('/\b(first|second|third|pare|dyte|trete)\s+(product|customers?|clients?|one|result)\b|\b(product|customer|klient\w*)\s+(?:i |e )?(pare|dyte|trete)\b/',$q,$match))return null;
        $ordinal=$match[1]?:($match[4]??'');$index=array_search($ordinal,['first','second','third']);
        if($index===false)$index=array_search($ordinal,['pare','dyte','trete']);
        $type = preg_match('/customer|client|klient/', $match[2] ?: ($match[3] ?? '')) ? 'customer' : 'product';
        $rows=array_values(array_filter($context['result_entities']??[],fn($e)=>($e['type']??null)===$type));
        return $index!==false?($rows[$index]??null):null;
    }

    public function referencedCustomers(array $v,string $conversation,array $context):?array
    {
        $q=app(AssistantLanguage::class)->normalize($v['question']);
        if(!preg_match('/\b(they|them|those customer|these customer|those same|these buyer|ata|atyre|keta|ketyre)\b/',$q)||!preg_match('/owe|debt|overdue|balance|borxh|late|unpaid|paying/',$q))return null;
        $entities=array_slice(array_values(array_filter($context['result_entities']??[],fn($e)=>($e['type']??null)==='customer')),0,6);
        if(!$entities)return null;
        $runner=app(AssistantToolRunner::class);$results=[];
        foreach($entities as $entity)$results[]=['tool'=>'get_customer','data'=>$runner->execute('get_customer',['customer_id'=>(int)$entity['id']])];
        $sq=($v['language']??'en')==='sq';$reply=app(AssistantComposer::class)->compose($results,$sq);
        $reply['limitations'][]=$sq?'Deri gjashtë klientët e parë nga lista e mëparshme; gjendjet u lexuan sërish tani.':'Up to the first six customers from the previous list; balances were read again now.';
        return $reply+['id'=>(string)\Illuminate\Support\Str::uuid(),'conversation_id'=>$conversation,'intent'=>'referenced_customer_credit','text'=>$sq?'Gjendjet aktuale për '.count($entities).' klientët e parë nga lista e mëparshme. Paradhëniet mbeten veçmas.':'Current balances for the first '.count($entities).' customers in the previous list. Advances remain separate.','context_entities'=>$entities,'context'=>null,'choices'=>[],'read_only'=>true,'provider'=>'deterministic','state'=>'success','tools'=>$runner->executions];
    }

    public function warehouseComparison(array $v,string $conversation,array $context,bool $referencedOrder=false):?array
    {
        $q=app(AssistantLanguage::class)->normalize($v['question']);$entity=$v['entity']??$context['entity']??null;
        if(($entity['type']??null)!=='product'||!$referencedOrder&&!preg_match('/warehouse.*(?:more|most|higher|much)|which warehouse|warehouse.*(?:shume|shum)|another warehouse|other warehouse|depo\w* (?:tjeter|tjera)/',$q))return null;
        $runner=app(AssistantToolRunner::class);$data=$runner->execute('get_product_availability',['product_id'=>(int)$entity['id']]);
        $groups=collect($data['warehouses']??[])->groupBy('warehouse.id')->map(fn($rows)=>['name'=>data_get($rows->first(),'warehouse.name'),'available'=>round($rows->sum('available'),5)])->sortByDesc('available')->values();
        $sq=($v['language']??'en')==='sq';$reply=app(AssistantComposer::class)->compose([['tool'=>'get_product_availability','data'=>$data]],$sq);
        $url = (app(PermissionService::class)->roleHasPermission(auth()->user()->role, 'transfers.view') ? '/warehouse-operations' : '/products').'?product='.$entity['id'];
        $reply['cards']= $groups->map(fn($row)=>['tool'=>'get_product_availability','title'=>$row['name']??($sq?'Depoja':'Warehouse'),'url'=>$url,'metrics'=>[['label'=>$sq?'Në dispozicion':'Available','value'=>$row['available'],'unit'=>$data['product']['unit']]],'evidence'=>['basis'=>'Current authorized warehouse rows, maximum 50 locations.']])->all();
        $reply['limitations'][]=$sq?'Krahasimi përfshin deri 50 lokacione të burimit; nuk është renditje e plotë për depo më të mëdha.':'Comparison covers up to 50 source locations; not a complete ranking for larger warehouses.';
        return $reply+['id'=>(string)\Illuminate\Support\Str::uuid(),'conversation_id'=>$conversation,'intent'=>'warehouse_comparison','text'=>$sq?'Sasia e disponueshme sipas depos për produktin e zgjedhur, nga më e madhja.':'Available stock by warehouse for the selected product, highest first.','context'=>$entity,'context_entities'=>[$entity],'choices'=>[],'provider'=>'deterministic','state'=>'success','read_only'=>true,'tools'=>$runner->executions];
    }

    public function question(string $question, array $context): string
    {
        $q=app(AssistantLanguage::class)->normalize($question);
        if(($context['entity']['type']??null)==='product'&&!empty($context['entity']['title'])&&preg_match('/\b(?:this|that|kete|kjo)\s+(?:shortage|munges\w*)\b/',$q)&&preg_match('/should|recommend|review|\bdo\b|bo|vepro/',$q))return 'Should we reorder "'.str_replace('"','',$context['entity']['title']).'"?';
        if (($context['last_intent']??null)!=='sales' || empty($context['sales_period'])) return $question;
        if (preg_match('/^(?:(?:what about|and|po|e|per)\s+)?(?:the\s+)?(?:month before|previous month|muaji[n]? (?:para|paraprak)|muajin e meparshem)[?!. ]*$/',$q)) {
            $start=CarbonImmutable::parse($context['sales_period']['from'])->subMonthNoOverflow()->startOfMonth();
            return 'sales '.$start->toDateString().' '.$start->endOfMonth()->toDateString();
        }
        return $question;
    }
}
