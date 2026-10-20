<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\CustomerDebtTransaction;
use App\Models\Invoice;
use App\Support\CompanyClock;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Read-only finance evidence. No financial command is registered here. */
class FinanceAssistantFacts
{
    public const TOOLS = ['get_vat_control_facts','get_fiscalization_facts','get_tax_mapping_gaps','get_accounting_control_summary', 'get_accounting_receivables', 'get_accounting_payables', 'get_month_close_blockers', 'get_financial_reconciliation', 'get_payment_allocation_review', 'get_posted_profit_comparison'];

    public function read(string $tool, array $input = []): array
    {
        $to = $input['to'] ?? CompanyClock::today()->toDateString();
        validator(['to' => $to, 'from' => $input['from'] ?? null], ['to' => 'required|date_format:Y-m-d', 'from' => 'nullable|date_format:Y-m-d|before_or_equal:to'])->validate();
        switch ($tool) {
            case 'get_vat_control_facts':
                $p=app(VatReportingService::class)->period(substr($to,0,7));
                return array_intersect_key($p,array_flip(['period','rows','warnings','unmapped','reconciliation','ready_for_review','qualification']));
            case 'get_fiscalization_facts':
                $p=app(FiscalWorkspace::class)->summary();
                return array_intersect_key($p,array_flip(['environment','counts','recent_failures','readiness','products_missing_tax']));
            case 'get_tax_mapping_gaps':
                return ['count'=>\App\Models\Product::whereNull('default_tax_code_id')->count(),'items'=>\App\Models\Product::whereNull('default_tax_code_id')->orderBy('id')->limit(10)->get(['id','name','sku'])->toArray(),'qualification'=>'Missing classifications require an accountant decision. Product names are not tax rules.'];

            case 'get_accounting_control_summary':
                $data = app(FinancialControlService::class)->summary($input['from'] ?? null, $to);

                return array_intersect_key($data, array_flip(['currency', 'from', 'to', 'summary', 'counts', 'attention', 'current_period', 'books_balanced', 'working_capital', 'checked_at']));
            case 'get_accounting_receivables':
                $data = app(ReceivableReports::class)->aging($to);

                return ['as_of' => $to, 'currency' => $data['currency'], 'summary' => $data['summary'], 'aging_buckets' => $data['aging_buckets'], 'top_customers' => app(ReceivableReports::class)->positions($to)->where('currency', $data['currency'])->selectRaw('customer_id,customer_name,SUM(outstanding) outstanding')->groupBy('customer_id', 'customer_name')->orderByDesc('outstanding')->limit(10)->get()->toArray(), 'items' => array_slice($data['receivables'], 0, 10), 'qualification' => $data['note']];
            case 'get_accounting_payables':
                $data = app(PayableReports::class)->aging($to);
                $next = CarbonImmutable::parse($to)->addDays(7)->toDateString();
                $due = app(PayableReports::class)->positions($to)->whereDate('due_date', '>=', $to)->whereDate('due_date', '<=', $next);

                return ['as_of' => $to, 'currency' => $data['base_currency'], 'summary' => $data['summary'], 'known_due_through' => $next, 'known_due_amount' => Money::normalize((clone $due)->sum('base_outstanding')), 'items' => (clone $due)->orderBy('due_date')->orderBy('id')->limit(10)->get()->toArray(), 'qualification' => $data['note']];
            case 'get_month_close_blockers':
                $p = isset($input['period_id']) ? AccountingPeriod::findOrFail($input['period_id']) : AccountingPeriod::where('status', '!=', 'closed')->whereDate('ends_at', '<=', $to)->orderByDesc('ends_at')->first();
                if (! $p) {
                    return ['period' => null, 'status' => 'ATTENTION', 'blockers' => [], 'qualification' => 'Select or create the accounting period to inspect month close.'];
                }
                $data = app(FinancialControlService::class)->readiness($p);

                return ['period' => $p->only(['id', 'name', 'starts_at', 'ends_at', 'status']), 'status' => $data['status'], 'ready_to_close' => $data['ready_to_close'], 'blockers' => collect($data['checks'])->filter(fn ($c) => $c['required'] && ! $c['passed'])->take(15)->values()->all(), 'qualification' => $data['basis']];
            case 'get_financial_reconciliation':return app(DatedFinancialReconciliation::class)->report($to);
            case 'get_payment_allocation_review':
                $company = Auth::user()->company_id;
                $allocated = DB::table('customer_settlement_allocations')->where('company_id', $company)->whereNull('released_on')->selectRaw('settlement_transaction_id,SUM(amount) assigned')->groupBy('settlement_transaction_id');
                $rows = DB::table('customer_debt_transactions as p')->join('customers as c', fn ($j) => $j->on('c.id', '=', 'p.customer_id')->where('c.company_id', $company))->leftJoinSub($allocated, 'a', 'a.settlement_transaction_id', '=', 'p.id')
                    ->where('p.company_id', $company)->where('p.type', 'payment')->whereNull('p.reversed_transaction_id')->whereDate('p.transaction_date', '<=', $to)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customer_debt_transactions as r')->where('r.company_id', $company)->whereColumn('r.reversed_transaction_id', 'p.id'))
                    ->whereRaw('p.balance_before-p.balance_after-COALESCE(a.assigned,0)>0.005')->selectRaw('p.id,p.customer_id,c.name customer_name,p.transaction_date,p.reference_number,p.balance_before-p.balance_after-COALESCE(a.assigned,0) unassigned');

                return ['currency' => CompanyCurrency::current(), 'count' => (clone $rows)->count(), 'items' => $rows->orderBy('p.transaction_date')->orderBy('p.id')->limit(10)->get()->toArray(), 'qualification' => 'Review explicit AR attribution. Existing oldest-known-due-first attribution remains valid; these are not new cash receipts. Customer and supplier advances remain separate.'];
            case 'get_posted_profit_comparison':
                $end = CarbonImmutable::parse($to);
                $from = $input['from'] ?? $end->startOfMonth()->toDateString();
                $previousFrom = $end->subMonthNoOverflow()->startOfMonth()->toDateString();
                $previousTo = $end->subMonthNoOverflow()->endOfMonth()->toDateString();
                $current = app(AccountingService::class)->profitAndLoss($from, $to);
                $previous = app(AccountingService::class)->profitAndLoss($previousFrom, $previousTo);
                $hasPrevious = collect($previous['accounts'])->contains(fn ($a) => Money::compare($a['debit_movement'], 0) !== 0 || Money::compare($a['credit_movement'], 0) !== 0);
                $keys = ['revenue', 'cost_of_goods_sold', 'gross_profit', 'operating_expenses', 'other_gains_losses', 'net_profit'];

                return ['currency' => $current['currency'], 'from' => $from, 'to' => $to, 'comparison_from' => $previousFrom, 'comparison_to' => $previousTo, 'current' => array_intersect_key($current, array_flip($keys)), 'previous' => $hasPrevious ? array_intersect_key($previous, array_flip($keys)) : null, 'difference' => $hasPrevious ? Money::subtract($current['net_profit'], $previous['net_profit']) : null, 'qualification' => 'Posted results only. Date ranges are explicit. Differences identify accounting contributors, not proven commercial causes. No growth percentage is calculated without prior activity.'];
        }
        abort(404);
    }

    private function cache()
    {
        return Cache::store(app()->environment('testing') ? 'array' : 'file');
    }

    private function key(string $id): string
    {
        return 'assistant-finance:'.Auth::user()->company_id.':'.Auth::id().':'.$id;
    }

    public function clear(string $id): void
    {
        $this->cache()->forget($this->key($id));
    }

    public function intent(string $question, string $id): ?string
    {
        $q = app(AssistantLanguage::class)->normalize($question);
        $q = strtr($q, ['recievables' => 'receivables', 'receivbles' => 'receivables', 'suppllier' => 'supplier', 'alocation' => 'allocation', 'reconcilation' => 'reconciliation']);
        if (preg_match('/(?:change|alter|submit|fiscalize|ndrysho|dorezo|fiskalizo).*?(?:tax|vat|rate|return|certificate|invoice|tvsh|tatim|norm|fatur|certifik)/',$q)) return 'finance_action_boundary';
        if (preg_match('/(?:^|can you |please |a mund |te lutem )(?:post|reverse|allocate|close|reconcile|pay|posto|anulo|aloko|mbyll|paguaj)\b.*(?:journal|payment|period|bank|supplier|ditar|pagese|periudh|furnitor)/', $q)) {
            return 'finance_action_boundary';
        }
        foreach ([
            'finance_fiscal' => '/fiscali[sz]|fiskaliz/',
            'finance_tax_mapping' => '/(?:product|produkt).*(?:tax|vat|tvsh|tatim|mapping)|missing.*tax.*map/',
            'finance_vat' => '/\\bvat\\b|tvsh|tax.*(?:reconcil|clos)/',
            'finance_close' => '/month.?close|blocking.*clos|what.*block.*month|mbyll.*muaj|pengon.*mbyll|close.*(?:ready|block)/',
            'finance_reconciliation' => '/inventory.*(?:match|accounting|gl)|(?:ar|ap).*gl|subledger.*reconcil|reconcil.*difference|inventar.*(?:kontabil|perputh)|diferenc.*rakord/',
            'finance_allocation' => '/payment.*(?:allocat|unassigned)|unallocat.*payment|pages.*(?:alok|pacakt)/',
            'finance_payables' => '/(?:owe|owing).*supplier|bills?.*(?:due|next)|payable|detyrim.*furnitor|furnitor.*(?:borxh|detyro)|fatura.*(?:ardhsh|javen|afat)/',
            'finance_receivables' => '/customer.*(?:owe|owing|debt)|debt.*customer|which.*(?:owe|debt)|who.*owes|overdue.*(?:invoice|customer)|receivable|debtor|klient.*borxh|borxh.*klient|kush.*detyro/',
            'finance_profit' => '/profit.*(?:drop|fall|month|compar)|(?:why|pse).*(?:profit|fitim)|fitim.*(?:muaj|krahas)/',
            'finance_cash' => '/how much.*(?:cash|bank)|cash.*(?:have|balance)|sa.*(?:para|mjete|arke|bank)|gjend.*bank/',
            'finance_summary' => '/financial state|finance summary|accounting summary|gjendja financiare|pasqyra financiare/',
        ] as $intent => $regex) {
            if (preg_match($regex, $q)) {
                return $intent;
            }
        }
        if (preg_match('/^(why|pse|show customer|show customers|shfaq customer|shfaq klientet|compare last month|krahaso muajin e kaluar)[?!. ]*$/', $q)) {
            $last = $this->cache()->get($this->key($id));
            if ($last) {
                return str_contains($q, 'compar') || str_contains($q, 'krahas') ? (str_starts_with($last, 'finance_receivables') ? 'finance_receivables_compare' : 'finance_profit') : $last;
            }
        }
        $this->clear($id);

        return null;
    }

    public function reply(string $intent, string $question, string $id, bool $sq): array
    {
        $text = $sq ? 'Kontrollet financiare kërkojnë shqyrtim dhe veprim të njeriut në ndërfaqe. AIMS nuk poston, anulon, alokon, mbyll, rakordon ose paguan automatikisht.' : 'Financial actions require human review in the source workspace. Ask AIMS cannot post, reverse, allocate, close, reconcile or pay automatically.';
        $cards = [];
        $tools = [];
        $sources = [];
        $limits = [];
        if ($intent !== 'finance_action_boundary') {
            $tool = match ($intent) {
                'finance_vat'=>'get_vat_control_facts','finance_fiscal'=>'get_fiscalization_facts','finance_tax_mapping'=>'get_tax_mapping_gaps',
                'finance_receivables','finance_receivables_compare' => 'get_accounting_receivables','finance_payables' => 'get_accounting_payables','finance_close' => 'get_month_close_blockers','finance_reconciliation' => 'get_financial_reconciliation','finance_allocation' => 'get_payment_allocation_review','finance_profit' => 'get_posted_profit_comparison',default => 'get_accounting_control_summary'
            };
            $runner = app(AssistantToolRunner::class);
            $data = $runner->execute($tool);
            $tools = $runner->executions;
            $currency = $data['currency'] ?? CompanyCurrency::current();
            $format = fn ($v) => ($v === null ? '—' : Money::normalize($v).' '.$currency);
            $url = '/accounting?tab='.match ($intent) {
                'finance_receivables','finance_allocation' => 'ar','finance_payables' => 'ap','finance_close' => 'periods','finance_reconciliation' => 'reconciliation','finance_profit' => 'reports',default => 'overview'
            };
            if(in_array($intent,['finance_vat','finance_tax_mapping','finance_fiscal']))$url=$intent==='finance_fiscal'?'/fiscalization':'/tax';
            $metrics = [];
            switch ($intent) {
                case 'finance_vat':
                    $text=$data['reconciliation']['reconciled']?($sq?'TVSH përputhet me librin kryesor.':'VAT source totals reconcile with the General Ledger.'):($sq?'Ka diferenca TVSH/GL për shqyrtim.':'VAT/GL differences require review.');
                    $metrics=[[$sq?'TVSH dalëse':'Output VAT',$data['reconciliation']['controls']['output_vat']['operational_total']],[$sq?'TVSH hyrëse':'Input VAT',$data['reconciliation']['controls']['input_vat']['operational_total']]];
                    $limits[]=count($data['unmapped']).' unmapped treatment groups. '.count($data['warnings']).' period review notices. No return is submitted.';break;
                case 'finance_fiscal':
                    $text=($sq?'Fiskalizimi TEST: ':'TEST fiscalization: ').($data['counts']['failed']??0).' failed, '.($data['counts']['unknown']??0).' unknown. '.($sq?'Kërkohet shqyrtim njerëzor.':'Human review is required.');
                    foreach($data['recent_failures'] as $r)$cards[]=['tool'=>$tool,'title'=>'#'.$r->coupon_no.' · '.$r->status,'url'=>'/fiscalization?receipt='.$r->id,'summary'=>$r->operator_message,'metrics'=>[]];
                    $limits[]='ATK TEST interoperability: '.$data['readiness']['official_api_result'].'. Official certification: NO.';break;
                case 'finance_tax_mapping':
                    $text=$data['count'].($sq?' produkte pa kod tatimor.':' products have no default tax code.');
                    foreach($data['items'] as $p)$cards[]=['tool'=>$tool,'title'=>$p['name'],'url'=>'/tax?tab=configuration','summary'=>$p['sku'],'metrics'=>[]];break;
                case 'finance_receivables_compare':
                case 'finance_receivables':$s = $data['summary'];
                    $text = ($sq ? 'Klientët na detyrohen ' : 'Customers owe us ').$format($s['total_outstanding']).'.';
                    $metrics = [[$sq ? 'Të vonuara' : 'Overdue', $s['overdue_total']], ['90+', $s['overdue_90_plus']], [$sq ? 'Afati i panjohur' : 'Unknown due', $s['unknown_due']]];
                    foreach (array_slice((array) $data['top_customers'], 0, 5) as $c) {
                        $c = (array) $c;
                        $cards[] = ['tool' => $tool, 'title' => $c['customer_name'] ?: '#'.$c['customer_id'], 'url' => '/accounting?tab=ar&customer='.$c['customer_id'], 'summary' => $sq ? 'Borxh i hapur' : 'Outstanding receivable', 'metrics' => [['label' => $sq ? 'Mbetja' : 'Outstanding', 'value' => $c['outstanding'], 'unit' => $currency]]];
                    }break;
                case 'finance_payables':$text = ($sq ? 'Detyrimet ndaj furnitorëve janë ' : 'Supplier payables are ').$format($data['summary']['total']).'.';
                    $metrics = [[$sq ? 'Afatet e njohura në 7 ditë' : 'Known due within 7 days', $data['known_due_amount']], [$sq ? 'Pa afat të njohur' : 'Unknown due', $data['summary']['unknown_due']]];
                    break;
                case 'finance_close':$text = ($data['period']['name'] ?? ($sq ? 'Zgjidh periudhën' : 'Select a period')).' · '.$data['status'].'.';
                    foreach (array_slice($data['blockers'], 0, 5) as $b) {
                        $cards[] = ['tool' => $tool, 'title' => $b['label'], 'url' => $b['url'], 'summary' => $b['detail'] ?? '', 'metrics' => isset($b['difference']) ? [['label' => $sq ? 'Diferenca' : 'Difference', 'value' => $b['difference'], 'unit' => $currency]] : []];
                    }break;
                case 'finance_reconciliation':$text = $data['all_reconciled'] ? ($sq ? 'Të gjitha nënlibrat e provuar përputhen me librin kryesor.' : 'All proven subledgers reconcile to the General Ledger.') : ($sq ? 'Ka diferenca ose prova që mungojnë. Shqyrtoni burimet para mbylljes.' : 'Differences or missing evidence need review before closing.');
                    foreach ($data['rows'] as $r) {
                        $cards[] = ['tool' => $tool, 'title' => ucwords(str_replace('_', ' ', $r['control'])), 'url' => $r['drilldown_url'], 'summary' => $r['status'].($r['evidence_issues'] ? ' · '.implode(' ', $r['evidence_issues']) : ''), 'metrics' => [['label' => $sq ? 'Diferenca' : 'Difference', 'value' => $r['difference'], 'unit' => $currency]]];
                    }break;
                case 'finance_allocation':$text = $data['count'].($sq ? ' pagesa kanë caktim të borxhit për shqyrtim.' : ' payments have AR attribution available for review.');
                    foreach (array_slice($data['items'], 0, 5) as $p) {
                        $p = (array) $p;
                        $cards[] = ['tool' => $tool, 'title' => $p['customer_name'], 'url' => '/accounting?tab=ar&customer='.$p['customer_id'], 'summary' => $p['reference_number'] ?: '#'.$p['id'], 'metrics' => [['label' => $sq ? 'Pa caktim të shprehur' : 'Without explicit attribution', 'value' => $p['unassigned'], 'unit' => $currency]]];
                    }break;
                case 'finance_profit':$text = ($sq ? 'Rezultati neto i postuar: ' : 'Posted net result: ').$format($data['current']['net_profit']).'. '.($data['previous'] ? ($sq ? 'Ndryshimi kundrejt periudhës së krahasimit: ' : 'Change against the comparison period: ').$format($data['difference']).'.' : ($sq ? 'Nuk ka aktivitet të mëparshëm për krahasim.' : 'No prior activity exists for comparison.'));
                    $metrics = [[$sq ? 'Të ardhurat' : 'Revenue', $data['current']['revenue']], [$sq ? 'Kostoja e shitjeve' : 'Cost of sales', $data['current']['cost_of_goods_sold']], [$sq ? 'Shpenzimet operative' : 'Operating expenses', $data['current']['operating_expenses']]];
                    if ($data['previous']) {
                        $cards[] = ['tool' => $tool, 'title' => $data['comparison_from'].' — '.$data['comparison_to'], 'url' => $url, 'summary' => $sq ? 'Periudha e krahasimit' : 'Comparison period', 'metrics' => [['label' => $sq ? 'Rezultati neto' : 'Net result', 'value' => $data['previous']['net_profit'], 'unit' => $currency]]];
                    }break;
                default:$s = $data['summary'];
                    $text = ($sq ? 'Arka dhe banka: ' : 'Cash and bank: ').$format($s['cash'] === null || $s['bank'] === null ? null : Money::add($s['cash'], $s['bank'])).'.';
                    $metrics = [[$sq ? 'Borxhet e klientëve' : 'Customers owe', $s['accounts_receivable']], [$sq ? 'Detyrimet ndaj furnitorëve' : 'We owe suppliers', $s['accounts_payable']]];
            }
            if ($intent === 'finance_receivables_compare') {
                $previousDate = CompanyClock::today()->startOfMonth()->subDay()->toDateString();
                $hasPrior = CustomerDebtTransaction::query()->whereDate('transaction_date', '<=', $previousDate)->exists() || Invoice::query()->whereNotNull('issued_at')->whereDate('invoice_date', '<=', $previousDate)->exists();
                if ($hasPrior) {
                    $previous = $runner->execute('get_accounting_receivables', ['to' => $previousDate]);
                    $cards[] = ['tool' => $tool, 'title' => $previousDate, 'url' => $url, 'summary' => $sq ? 'Gjendja në fund të muajit të kaluar' : 'Previous month-end position', 'metrics' => [['label' => $sq ? 'Borxhet e klientëve' : 'Customers owe', 'value' => $previous['summary']['total_outstanding'], 'unit' => $currency]]];
                } else {
                    $limits[] = $sq ? 'Nuk ka aktivitet të mëparshëm për krahasim.' : 'No prior activity exists for comparison.';
                }
                $tools = $runner->executions;
            }
            array_unshift($cards, ['tool' => $tool, 'title' => $sq ? 'Kontrollet financiare AIMS' : 'AIMS financial controls', 'url' => $url, 'summary' => $text, 'metrics' => array_map(fn ($m) => ['label' => $m[0], 'value' => $m[1], 'unit' => $currency], $metrics), 'evidence' => ['as_of' => $data['as_of'] ?? $data['to'] ?? CompanyClock::today()->toDateString(), 'is_prediction' => false]]);
            $sources[] = ['tool' => $tool, 'url' => $url];
            $limits[] = ($data['qualification'] ?? ($sq ? 'Vetëm burime të postuara; hap hapësirën për hollësi.' : 'Posted evidence only; open the workspace for detail.'));
            $this->cache()->put($this->key($id), $intent, now()->addMinutes(30));
        }

        $response = ['id' => (string) Str::uuid(), 'conversation_id' => $id, 'intent' => $intent, 'text' => $text, 'cards' => array_slice($cards, 0, 7), 'sources' => $sources, 'limitations' => $limits, 'choices' => [], 'follow_ups' => $intent === 'finance_receivables' ? ['Why?', 'Show customers', 'Compare last month'] : [], 'context' => null, 'context_entities' => [], 'read_only' => true, 'provider' => 'deterministic', 'state' => 'success', 'is_prediction' => false, 'tools' => $tools];
        app(AnalyticsDataService::class)->audit('assistant.query', ['response_id' => $response['id'], 'conversation_id' => $id, 'question' => $question, 'intent' => $intent, 'tools' => $tools, 'action_preview' => false, 'response_state' => 'success', 'provider' => 'deterministic', 'follow_up' => false, 'language' => $sq ? 'sq' : 'en']);

        return $response;
    }
}
