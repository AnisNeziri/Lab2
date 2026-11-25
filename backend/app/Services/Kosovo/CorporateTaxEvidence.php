<?php

namespace App\Services\Kosovo;

use App\Models\AccountingPeriod;
use App\Models\CorporateTaxReview;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

final class CorporateTaxEvidence
{
    public function digest(AccountingPeriod $period): string
    {
        $company = $period->company_id;
        $generated = CorporateTaxReview::query()->where('accounting_period_id', $period->id)
            ->whereNotNull('journal_entry_id')->pluck('journal_entry_id');
        $journals = DB::table('journal_entries as j')->where('j.company_id', $company)
            ->where('j.posting_date', '>=', $period->starts_at->toDateString())
            ->where('j.posting_date', '<', $period->ends_at->toImmutable()->addDay()->toDateString())
            ->whereIn('j.status', ['posted', 'reversed'])->whereNotIn('j.id', $generated);
        $hash = hash_init('sha256');
        $lines = (clone $journals)->join('journal_lines as l', 'l.journal_entry_id', '=', 'j.id')
            ->join('accounting_accounts as a', fn ($join) => $join->on('a.id', '=', 'l.accounting_account_id')->on('a.company_id', '=', 'j.company_id'))
            ->leftJoin('invoices as i', fn ($join) => $join->on('i.id', '=', 'j.source_id')->on('i.company_id', '=', 'j.company_id')
                ->whereIn('j.source_type', ['invoice', 'order_invoice_tax']))
            ->select(['l.id', 'l.line_number', 'j.id as journal_id', 'j.journal_number', 'j.posting_date',
                'j.source_type', 'j.source_id', 'j.source_module', 'j.source_key', 'j.revision', 'j.reversal_of_id', 'j.correction_of_id',
                'j.description', 'j.reference_number', 'j.currency', 'j.exchange_rate', 'j.exchange_rate_date', 'j.exchange_rate_source',
                'l.accounting_account_id', 'l.description as line_description', 'l.debit', 'l.credit', 'l.foreign_debit', 'l.foreign_credit',
                'l.counterparty_type', 'l.counterparty_id', 'l.dimensions',
                'a.code', 'a.type', 'a.statement_group', 'a.parent_id', 'a.normal_balance', 'i.integrity_hash'])
            ->lazyById(500, 'l.id', 'id');
        foreach ($lines as $line) {
            $row = (array) $line;
            foreach (['debit', 'credit', 'foreign_debit', 'foreign_credit'] as $amount) {
                $row[$amount] = Money::normalize($row[$amount] ?? 0);
            }
            $row['exchange_rate'] = Money::normalizeDecimal($row['exchange_rate'], 8);
            $row['dimensions'] = json_decode($row['dimensions'] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
            hash_update($hash, self::encode($row)."\n");
        }

        // Binary contents and document descriptions stay in Document Center.
        // Only the digest of current source-linked version evidence is retained.
        $documents = DB::table('document_links as d')
            ->join('documents as doc', fn ($join) => $join->on('doc.id', '=', 'd.document_id')->on('doc.company_id', '=', 'd.company_id'))
            ->leftJoin('document_versions as v', fn ($join) => $join->on('v.document_id', '=', 'doc.id')
                ->on('v.version', '=', 'doc.current_version')->on('v.company_id', '=', 'doc.company_id'))
            ->where('d.company_id', $company)
            ->whereExists((clone $journals)->selectRaw('1')->where(function ($q) {
                $q->where(fn ($link) => $link->where('d.entity_type', 'journal')->whereColumn('d.entity_id', 'j.id'))
                    ->orWhere(fn ($link) => $link->whereIn('j.source_type', ['invoice', 'order_invoice_tax'])->where('d.entity_type', 'invoice')->whereColumn('d.entity_id', 'j.source_id'))
                    ->orWhere(fn ($link) => $link->where('j.source_type', 'expense')->where('d.entity_type', 'expense')->whereColumn('d.entity_id', 'j.source_id'));
            }))
            ->select('d.id', 'd.entity_type', 'd.entity_id', 'doc.uuid', 'doc.current_version', 'doc.status', 'v.checksum')
            ->lazyById(250, 'd.id', 'id');
        foreach ($documents as $document) {
            hash_update($hash, self::encode((array) $document)."\n");
        }

        return hash_final($hash);
    }

    public static function hash(array $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function encode(array $value): string
    {
        return json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? self::canonical($item) : $item, $value);
    }
}
