<?php

namespace App\Services;

use App\Models\AccountingException;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Read-only checks shared by accountant, owner and assistant projections. */
class FinancialIntegrityChecks
{
    public function journalDifferences(string $status = 'posted', ?string $to = null): int
    {
        $q = DB::table('journal_entries as j')->leftJoin('journal_lines as l', 'l.journal_entry_id', '=', 'j.id')
            ->where('j.company_id', Auth::user()->company_id)
            ->whereIn('j.status', $status === 'posted' ? ['posted', 'reversed'] : ['draft'])
            ->when($to, fn ($q) => $q->where('j.posting_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString()))
            ->groupBy('j.id', 'j.total_debit', 'j.total_credit')
            ->selectRaw('j.id')->havingRaw('COALESCE(SUM(l.debit),0) <> COALESCE(SUM(l.credit),0) OR j.total_debit <> COALESCE(SUM(l.debit),0) OR j.total_credit <> COALESCE(SUM(l.credit),0) OR COALESCE(SUM(l.debit),0) <= 0');

        return DB::query()->fromSub($q, 'differences')->count();
    }

    public function checks(?string $to = null): array
    {
        $company = (int) Auth::user()->company_id;
        $unbalanced = $this->journalDifferences('posted', $to);
        $crossCompany = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')->where('j.company_id', $company)->where('a.company_id', '!=', $company)->count();
        $badLines = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.company_id', $company)->whereIn('j.status', ['posted', 'reversed'])
            ->where(fn ($q) => $q->where('l.debit', '<', 0)->orWhere('l.credit', '<', 0)->orWhere(fn ($x) => $x->where('l.debit', '>', 0)->where('l.credit', '>', 0)))->count();
        $orphans = 0;
        foreach (AccountingJournalExperience::SOURCES as $type => [$model]) {
            $class = 'App\\Models\\'.$model;
            $table = (new $class)->getTable();
            // JournalLine is not used as a tenant root: every check starts at
            // the authenticated company's journal, then checks source company.
            $orphans += JournalEntry::query()->where('source_type', $type)->whereNotNull('source_id')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($table.' as source')->whereColumn('source.id', 'journal_entries.source_id')->where('source.company_id', $company))->count();
        }
        $late = DB::table('journal_entries as j')->join('accounting_periods as p', function ($join) {
            $join->on('p.company_id', '=', 'j.company_id')->whereColumn('j.posting_date', '>=', 'p.starts_at')->whereColumn('j.posting_date', '<=', 'p.ends_at');
        })->where('j.company_id', $company)->where('p.status', 'closed')->whereIn('j.status', ['posted', 'reversed'])->whereColumn('j.posted_at', '>', 'p.closed_at')->count();
        $missing = 0;
        $sources = [
            ['expenses', 'expense', fn ($q) => $q->where('status', 'posted')],
            ['invoices', 'invoice', fn ($q) => $q->whereNull('daily_sale_id')->where('document_type', 'invoice')->whereIn('status', ['issued', 'partially_paid', 'paid'])],
            ['landed_costs', 'landed_cost', fn ($q) => $q->where('status', 'posted')],
        ];
        foreach ($sources as [$table, $type, $scope]) {
            $q = DB::table($table.' as source')->where('source.company_id', $company);
            $scope($q);
            $missing += $q->whereNotExists(fn ($j) => $j->selectRaw('1')->from('journal_entries as posted')
                ->where('posted.company_id', $company)->where('posted.source_type', $type)->whereColumn('posted.source_id', 'source.id')->whereIn('posted.status', ['posted', 'reversed']))->count();
        }
        $exceptions = AccountingException::query()->where('status', 'open')->where('severity', 'critical')->count();
        $archived = JournalEntry::query()->where('source_type', 'like', 'archived:%')->whereIn('status', ['posted', 'reversed'])->count();
        $bankLinks = DB::table('bank_statement_rows as r')->join('bank_statements as s', 's.id', '=', 'r.bank_statement_id')
            ->join('financial_accounts as a', 'a.id', '=', 's.financial_account_id')->where('r.company_id', $company)
            ->where(fn ($q) => $q->where('s.company_id', '!=', $company)->orWhere('a.company_id', '!=', $company))->count();
        $matches = DB::table('bank_statement_matches as m')->leftJoin('bank_statement_rows as r', 'r.id', '=', 'm.bank_statement_row_id')
            ->leftJoin('bank_statements as s', 's.id', '=', 'r.bank_statement_id')
            ->leftJoin('financial_account_transactions as f', 'f.id', '=', 'm.active_transaction_id')
            ->leftJoin('journal_entries as j', 'j.id', '=', 'f.journal_entry_id')
            ->where('m.company_id', $company)->whereNotNull('m.active_transaction_id')->where(function ($q) use ($company) {
                $q->whereNull('r.id')->orWhereNull('s.id')->orWhereNull('f.id')->orWhereNull('j.id')
                    ->orWhere('r.company_id', '!=', $company)->orWhere('s.company_id', '!=', $company)->orWhere('f.company_id', '!=', $company)->orWhere('j.company_id', '!=', $company)
                    ->orWhere('r.status', '!=', 'reconciled')->orWhere('f.status', '!=', 'posted')->orWhere('j.status', '!=', 'posted')
                    ->orWhereColumn('f.financial_account_id', '!=', 's.financial_account_id')->orWhereColumn('m.active_transaction_id', '!=', 'm.financial_account_transaction_id')
                    ->orWhereRaw("m.signed_amount <> CASE WHEN f.type IN ('inflow','transfer_in','adjustment_in','refund_in') THEN f.amount ELSE -f.amount END");
            })->count();
        $sums = DB::table('bank_statement_rows as r')->leftJoin('bank_statement_matches as m', fn ($j) => $j->on('m.bank_statement_row_id', '=', 'r.id')->whereNotNull('m.active_transaction_id'))
            ->where('r.company_id', $company)->where('r.status', 'reconciled')->groupBy('r.id', 'r.amount')->select('r.id')->havingRaw('COALESCE(SUM(m.signed_amount),0) <> r.amount');
        $matches += DB::query()->fromSub($sums, 'bad_bank_sums')->count();
        $legacyOwners = DB::table('bank_statement_rows')->where('company_id', $company)->where('status', 'reconciled')->whereNotNull('matched_transaction_id')
            ->select('matched_transaction_id')->groupBy('matched_transaction_id')->havingRaw('COUNT(*) > 1');
        $matches += DB::query()->fromSub($legacyOwners, 'duplicate_owners')->count();

        return collect([
            ['posted_balance', 'Posted journal lines and totals balance', $unbalanced],
            ['company_links', 'Financial records belong to one company', $crossCompany],
            ['valid_lines', 'Posted debit and credit lines are valid', $badLines],
            ['source_links', 'Posted financial sources are traceable', $orphans],
            ['required_postings', 'Finalized financial documents have journals', $missing],
            ['closed_period_postings', 'No posting occurred after final period close', $late],
            ['critical_exceptions', 'No unresolved critical accounting exception', $exceptions],
            ['restored_source_links', 'Restored financial sources have resolved company-local links', $archived],
            ['bank_company_links', 'Bank statements and accounts belong to one company', $bankLinks],
            ['bank_match_integrity', 'Bank matches have exclusive, balanced, valid posted ownership', $matches],
        ])->map(fn ($c) => ['code' => $c[0], 'label' => $c[1], 'count' => $c[2], 'passed' => $c[2] === 0,
            'required' => true, 'severity' => 'critical', 'url' => '/accounting?tab=integrity'])->all();
    }
}
