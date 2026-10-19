<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TaxIntegrityChecks
{
    public function checks(?string $to = null): array
    {
        $company = Auth::user()->company_id;
        $recon = app(VatReportingService::class)->reconciliation(null, $to);
        $queries = [];
        $add = function ($query, $code) use (&$queries) {
            $queries[] = $query->selectRaw('? AS code, COUNT(*) AS total', [$code]);
        };
        $add(DB::table('tax_codes as t')->leftJoin('accounting_accounts as o', 'o.id', '=', 't.output_account_id')->leftJoin('accounting_accounts as i', 'i.id', '=', 't.input_account_id')
            ->where('t.company_id', $company)->where('t.active', true)->where('t.rate', '>', 0)->where(function ($q) use ($company) {
                $q->where(fn ($x) => $x->where('t.sales_enabled', true)->where(fn ($v) => $v->whereNull('o.id')->orWhere('o.company_id', '!=', $company)->orWhere('o.is_active', false)->orWhere('o.is_posting', false)->orWhere('o.type', '!=', 'liability')))
                    ->orWhere(fn ($x) => $x->where('t.purchases_enabled', true)->where(fn ($v) => $v->whereNull('i.id')->orWhere('i.company_id', '!=', $company)->orWhere('i.is_active', false)->orWhere('i.is_posting', false)->orWhere('i.type', '!=', 'asset')));
            }), 'tax_mapping');
        $receipts = DB::table('fiscal_receipts as r')->where('r.company_id', $company);
        if ($to) {
            $receipts->where('r.created_at', '<', CarbonImmutable::parse($to)->addDay()->toDateString());
        }
        $add((clone $receipts)->where('r.status', 'fiscalized')->where(function ($q) {
            $q->whereNull('r.provider_transaction_id')->orWhereNull('r.fiscalized_at')->orWhereNull('r.request_fingerprint')
                ->orWhereNotExists(fn ($v) => $v->selectRaw('1')->from('fiscal_attempts as a')->whereColumn('a.company_id', 'r.company_id')->whereColumn('a.fiscal_receipt_id', 'r.id')->where('a.state', 'fiscalized')->whereNotNull('a.response_fingerprint')->whereNotNull('a.finished_at'));
        }), 'fiscal_evidence');
        $duplicates = (clone $receipts)->select('r.request_fingerprint')->groupBy('r.request_fingerprint')->havingRaw('COUNT(*)>1');
        $add(DB::query()->fromSub($duplicates, 'dupes'), 'fiscal_duplicate');
        // Required/review cases are attention, not a reason to rewrite or auto-submit history.
        $required = DB::table('invoices as i')->join('fiscal_configurations as c', fn ($j) => $j->on('c.company_id', '=', 'i.company_id')->where('c.environment', 'test')->where('c.activity_policy', 'retail'))
            ->where('i.company_id', $company)->whereNotNull('i.issued_at')->whereNotIn('i.status', ['draft', 'void'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fiscal_receipts as r')->whereColumn('r.invoice_id', 'i.id')->whereColumn('r.company_id', 'i.company_id')->where('r.environment', 'test')->where('r.status', 'fiscalized'));
        if ($to) {
            $required->where('i.invoice_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString());
        }
        $add($required, 'fiscal_unresolved');
        $sales = DB::table('invoices as i')->where('i.company_id', $company)->whereNotNull('i.issued_at')->where('i.document_type', 'invoice')->where('i.status', '!=', 'void')
            ->whereRaw("ROUND(i.vat_total,2) <> ROUND(COALESCE((SELECT SUM(l.credit-l.debit) FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id=j.id WHERE j.company_id=i.company_id AND j.source_type IN ('invoice','order_invoice_tax') AND j.source_id=i.id AND j.source_module='sales' AND j.reversal_of_id IS NULL AND j.status IN ('posted','reversed') AND l.accounting_account_id IN (SELECT output_account_id FROM tax_codes WHERE company_id=i.company_id AND output_account_id IS NOT NULL UNION SELECT accounting_account_id FROM accounting_posting_mappings WHERE company_id=i.company_id AND mapping_key='output_vat')),0),2)");
        if ($to) {
            $sales->where('i.invoice_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString());
        }
        $add($sales, 'tax_document_journal');
        foreach (['input_vat' => 'deductible_vat_amount_eur', 'output_vat' => 'self_assessed_vat_amount_eur'] as $domain => $amount) {
            $side = $domain === 'input_vat' ? 'debit-credit' : 'credit-debit';
            $column = $domain === 'input_vat' ? 'input_account_id' : 'output_account_id';
            $purchases = DB::table('expenses as e')->where('e.company_id', $company)->whereNotNull('e.posted_at')->whereIn('e.status', ['posted', 'reversed'])->whereNull('e.deleted_at')
                ->whereRaw("ROUND(CASE WHEN e.document_type='credit_note' THEN -e.$amount ELSE e.$amount END,2) <> ROUND(COALESCE((SELECT SUM(l.".str_replace('-', '-l.', $side).") FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id=j.id WHERE j.company_id=e.company_id AND j.source_type='expense' AND j.source_id=e.id AND j.source_module='supplier_accounting' AND j.reversal_of_id IS NULL AND j.status IN ('posted','reversed') AND l.accounting_account_id IN (SELECT $column FROM tax_codes WHERE company_id=e.company_id AND $column IS NOT NULL UNION SELECT accounting_account_id FROM accounting_posting_mappings WHERE company_id=e.company_id AND mapping_key='$domain')),0),2)");
            if ($to) {
                $purchases->where('e.invoice_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString());
            }
            $add($purchases, 'purchase_journal_'.$domain);
        }
        $all = array_shift($queries);
        foreach ($queries as $q) {
            $all->unionAll($q);
        }
        $counts = $all->get()->pluck('total', 'code');
        $checks = [];
        foreach (['vat_gl' => ['VAT source documents reconcile to tax GL controls', $recon['reconciled'] ? 0 : 1, true, 'critical', '/tax?tab=vat'],
            'tax_mapping' => ['Active tax codes have valid company-local control mappings', (int) $counts['tax_mapping'], true, 'critical', '/tax?tab=configuration'],
            'tax_document_journal' => ['Document VAT matches its posted tax journal', (int) $counts['tax_document_journal'] + (int) $counts['purchase_journal_input_vat'] + (int) $counts['purchase_journal_output_vat'], true, 'critical', '/tax?tab=vat'],
            'fiscal_evidence' => ['Fiscalized receipts retain complete immutable evidence', (int) $counts['fiscal_evidence'], true, 'critical', '/fiscalization'],
            'fiscal_duplicate' => ['Fiscal request fingerprints are unique', (int) $counts['fiscal_duplicate'], true, 'critical', '/fiscalization'],
            'fiscal_unresolved' => ['Required TEST fiscalization needs operator review', (int) $counts['fiscal_unresolved'], false, 'attention', '/fiscalization']] as $code => [$label,$count,$required,$severity,$url]) {
            $checks[] = compact('code','label','count','required','severity','url') + ['passed' => $count === 0];
        }

        return $checks;
    }
}
