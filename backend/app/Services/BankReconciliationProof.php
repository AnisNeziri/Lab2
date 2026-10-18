<?php

namespace App\Services;

use App\Models\BankStatement;
use App\Models\BankStatementMatch;
use App\Models\FinancialAccountTransaction;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Bank evidence is a read model, never a cash balance or posting authority. */
class BankReconciliationProof
{
    public function bankEvidence(BankStatement $statement): ?array
    {
        if (empty($statement->column_mapping['balance'])) {
            return null;
        }
        $descending = ($statement->column_mapping['_options']['row_order'] ?? 'ascending') === 'descending';
        $first = $statement->rows()->orderBy('transaction_date')->orderBy('id', $descending ? 'desc' : 'asc')->first(['amount', 'statement_balance']);
        $last = $statement->rows()->orderByDesc('transaction_date')->orderBy('id', $descending ? 'asc' : 'desc')->first(['statement_balance']);
        if ($first?->statement_balance === null || $last?->statement_balance === null) {
            return null;
        }

        return ['opening' => Money::subtract($first->statement_balance, $first->amount), 'closing' => Money::normalize($last->statement_balance)];
    }

    public function balance(BankStatement $statement, string $asOf): string
    {
        $account = $statement->account;
        $opening = $account->opening_date && $account->opening_date->toDateString() <= $asOf ? $account->opening_balance : '0.00';
        $movements = FinancialAccountTransaction::query()->where('financial_account_id', $account->id)->whereDate('transaction_date', '<=', $asOf)
            ->where(fn ($q) => $q->where('status', 'posted')->orWhere(fn ($r) => $r->where('status', 'reversed')->whereRaw('COALESCE(reversal_date,DATE(reversed_at)) > ?', [$asOf])))
            ->sum(DB::raw("CASE WHEN type IN ('inflow','transfer_in','adjustment_in','refund_in') THEN amount ELSE -amount END"));

        return Money::add($opening, $movements);
    }

    public function unreconciledSystem(?int $account = null, ?string $from = null, ?string $to = null)
    {
        return FinancialAccountTransaction::query()->where('status', 'posted')->when($account, fn ($q) => $q->where('financial_account_id', $account))
            ->whereHas('account', fn ($q) => $q->where('type', 'bank'))->when($from, fn ($q) => $q->whereDate('transaction_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('transaction_date', '<=', $to))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bank_statement_matches as m')->whereColumn('m.active_transaction_id', 'financial_account_transactions.id')->whereColumn('m.company_id', 'financial_account_transactions.company_id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bank_statement_rows as r')->whereColumn('r.matched_transaction_id', 'financial_account_transactions.id')->whereColumn('r.company_id', 'financial_account_transactions.company_id')->where('r.status', 'reconciled'));
    }

    public function summary(BankStatement $statement): array
    {
        $aggregate = $statement->rows()->selectRaw("COUNT(*) total,SUM(CASE WHEN status='reconciled' THEN 1 ELSE 0 END) matched,SUM(CASE WHEN status<>'reconciled' THEN 1 ELSE 0 END) unexplained,SUM(CASE WHEN duplicate_of_row_id IS NOT NULL AND duplicate_reviewed_at IS NULL THEN 1 ELSE 0 END) duplicates,COALESCE(SUM(amount),0) movement")->first();
        $to = $statement->period_to?->toDateString();
        $from = $statement->period_from?->toDateString();
        $aims = $to ? $this->balance($statement, $to) : null;
        $aimsOpening = $from ? $this->balance($statement, CarbonImmutable::parse($from)->subDay()->toDateString()) : null;
        if ($from && $statement->account->opening_date?->toDateString() === $from) {
            $aimsOpening = Money::add($aimsOpening, $statement->account->opening_balance);
        }
        $movementDifference = $statement->opening_balance === null || $statement->closing_balance === null ? null : Money::subtract(Money::add($statement->opening_balance, $aggregate->movement), $statement->closing_balance);
        $difference = $aims === null || $statement->closing_balance === null ? null : Money::subtract($aims, $statement->closing_balance);
        $openingDifference = $aimsOpening === null || $statement->opening_balance === null ? null : Money::subtract($aimsOpening, $statement->opening_balance);
        $stale = BankStatementMatch::query()->join('bank_statement_rows as r', 'r.id', '=', 'bank_statement_matches.bank_statement_row_id')
            ->join('financial_account_transactions as f', 'f.id', '=', 'bank_statement_matches.financial_account_transaction_id')->leftJoin('journal_entries as j', 'j.id', '=', 'f.journal_entry_id')
            ->where('r.bank_statement_id', $statement->id)->whereNotNull('bank_statement_matches.active_transaction_id')->where(fn ($q) => $q->where('f.status', '!=', 'posted')->orWhereNull('j.id')->orWhere('j.status', '!=', 'posted')
            ->orWhereColumn('f.company_id', '!=', 'r.company_id')->orWhereColumn('j.company_id', '!=', 'r.company_id')->orWhere('f.financial_account_id', '!=', $statement->financial_account_id)
            ->orWhereRaw("bank_statement_matches.signed_amount <> CASE WHEN f.type IN ('inflow','transfer_in','adjustment_in','refund_in') THEN f.amount ELSE -f.amount END"))->count();
        $stale += $statement->rows()->where('status', 'reconciled')->whereDoesntHave('matches', fn ($q) => $q->whereNotNull('active_transaction_id'))->count();
        $rowSums = DB::table('bank_statement_rows as r')->leftJoin('bank_statement_matches as m', fn ($j) => $j->on('m.bank_statement_row_id', '=', 'r.id')->whereNotNull('m.active_transaction_id'))
            ->where('r.bank_statement_id', $statement->id)->where('r.status', 'reconciled')->select('r.id')->groupBy('r.id', 'r.amount')->havingRaw('ROUND(COALESCE(SUM(m.signed_amount),0),2)<>r.amount');
        $stale += DB::query()->fromSub($rowSums, 'differences')->count();
        $base = CompanyCurrency::current();
        $gl = null;
        if ($to && $statement->account->accounting_account_id) {
            $gl = Money::normalize(DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
                ->where('j.company_id', $statement->company_id)->whereIn('j.status', ['posted', 'reversed'])->where('l.accounting_account_id', $statement->account->accounting_account_id)
                ->where('j.posting_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString())->sum(DB::raw('l.debit-l.credit')));
        }
        $glDifference = $statement->account->currency === $base && $gl !== null && $aims !== null ? Money::subtract($aims, $gl) : null;
        $system = $this->unreconciledSystem($statement->financial_account_id, $from, $to)->count();
        $bankEvidence = $this->bankEvidence($statement);
        $checks = [['balances_provided', 'Statement opening and closing balances are provided', $statement->opening_balance !== null && $statement->closing_balance !== null],
            ['bank_evidence_preserved', 'Header balances agree with the imported bank evidence', $bankEvidence === null || ($statement->opening_balance !== null && $statement->closing_balance !== null && Money::compare($bankEvidence['opening'], $statement->opening_balance) === 0 && Money::compare($bankEvidence['closing'], $statement->closing_balance) === 0)],
            ['statement_arithmetic', 'Opening + statement movements equals closing', $movementDifference !== null && Money::compare($movementDifference, 0) === 0],
            ['opening_agrees', 'Statement opening agrees with AIMS', $openingDifference !== null && Money::compare($openingDifference, 0) === 0],
            ['closing_agrees', 'Statement closing agrees with AIMS', $difference !== null && Money::compare($difference, 0) === 0],
            ['bank_items_explained', 'Every bank row has an explicit posted match', (int) $aggregate->unexplained === 0],
            ['system_items_explained', 'In-period AIMS bank activity has a statement match', $system === 0],
            ['duplicates_reviewed', 'Overlapping-import duplicate evidence is reviewed', (int) $aggregate->duplicates === 0],
            ['valid_matches', 'Matched source transactions and journals remain valid', $stale === 0],
            ['mapped_account', 'This bank account has its own GL mapping', $statement->account->accounting_account_id !== null],
            ['bank_gl_agrees', 'Base-currency bank position agrees with its GL account', $statement->account->currency !== $base || ($glDifference !== null && Money::compare($glDifference, 0) === 0)],
            ['opening_date_known', 'Nonzero account opening has an effective date', Money::compare($statement->account->opening_balance, 0) === 0 || $statement->account->opening_date !== null]];

        return ['currency' => $statement->account->currency, 'period_from' => $from, 'period_to' => $to, 'opening_balance' => $statement->opening_balance, 'closing_balance' => $statement->closing_balance,
            'aims_opening_balance' => $aimsOpening, 'aims_closing_balance' => $aims, 'statement_movements' => Money::normalize($aggregate->movement), 'opening_difference' => $openingDifference, 'difference' => $difference, 'statement_arithmetic_difference' => $movementDifference,
            'gl_balance' => $gl, 'gl_currency' => $base, 'gl_difference' => $glDifference, 'gl_qualification' => $statement->account->currency === $base ? 'Compared at base-currency carrying value.' : 'Foreign-currency bank statement is reconciled in its transaction currency. GL carrying value is shown separately; no unconfigured period-end revaluation is implied.',
            'balance_source' => $bankEvidence === null ? 'accountant_entered' : 'csv_running_balances', 'imported_bank_balances' => $bankEvidence,
            'total_rows' => (int) $aggregate->total, 'reconciled_rows' => (int) $aggregate->matched, 'unexplained_rows' => (int) $aggregate->unexplained, 'unreconciled_system_items' => $system, 'unreviewed_duplicates' => (int) $aggregate->duplicates, 'invalid_matches' => $stale,
            'ready_to_complete' => collect($checks)->every(fn ($c) => $c[2]), 'checks' => array_map(fn ($c) => ['code' => $c[0], 'label' => $c[1], 'passed' => $c[2]], $checks),
            'policy' => 'Statement matching does not create money or journals. Ignored nonzero bank rows remain unexplained until their source is recorded and matched. Source transactions retain exclusive ownership.'];
    }
}
