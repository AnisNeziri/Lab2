<?php

namespace App\Services;

use App\Models\DailySale;
use App\Models\InvoiceProfile;
use App\Models\TaxCode;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Read projections of the existing invoice/purchase documents and posted GL, never a new tax ledger. */
class VatReportingService
{
    public function reconciliation(?string $from = null, ?string $to = null): array
    {
        $queries = [];
        foreach (['input_vat', 'output_vat'] as $domain) {
            $ids = DB::table('accounting_posting_mappings')->where('company_id', $this->company())->where('mapping_key', $domain)->select('accounting_account_id');
            $ids->union(DB::table('tax_codes')->where('company_id', $this->company())->whereNotNull($domain === 'input_vat' ? 'input_account_id' : 'output_account_id')->select($domain === 'input_vat' ? 'input_account_id' : 'output_account_id'));
            $q = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.company_id', $this->company())->whereIn('j.status', ['posted', 'reversed'])->whereIn('l.accounting_account_id', $ids);
            $this->dates($q, 'j.posting_date', $from, $to);
            $queries[] = $q->selectRaw('? AS domain, COALESCE(SUM('.($domain === 'input_vat' ? 'l.debit-l.credit' : 'l.credit-l.debit').'),0) AS amount', ['gl_'.$domain]);
        }
        $sales = $this->sales();
        $this->dates($sales, 'i.invoice_date', $from, $to);
        $queries[] = $sales->selectRaw("'sales_output' AS domain, COALESCE(SUM(CASE WHEN i.document_type='credit_note' THEN -l.vat_amount ELSE l.vat_amount END),0) AS amount");
        foreach (['input' => 'deductible_vat_amount_eur', 'output' => 'self_assessed_vat_amount_eur'] as $domain => $column) {
            foreach ([false, true] as $reversal) {
                $q = $this->purchases();
                if ($reversal) {
                    $q->where('e.status', 'reversed')->whereNotNull('e.reversal_date');
                }
                $this->dates($q, $reversal ? 'e.reversal_date' : 'e.invoice_date', $from, $to);
                $sign = $reversal ? '-1' : '1';
                $queries[] = $q->selectRaw('? AS domain, COALESCE(SUM('.$sign." * CASE WHEN e.document_type='credit_note' THEN -e.".$column.' ELSE e.'.$column.' END),0) AS amount', ['purchase_'.$domain.($reversal ? '_reversal' : '')]);
            }
        }
        $all = array_shift($queries);
        foreach ($queries as $q) {
            $all->unionAll($q);
        }
        $totals = $all->get()->pluck('amount', 'domain');
        $output = Money::add($totals['sales_output'], $totals['purchase_output'], $totals['purchase_output_reversal']);
        $input = Money::add($totals['purchase_input'], $totals['purchase_input_reversal']);
        $controls = [];
        foreach (['output_vat' => $output, 'input_vat' => $input] as $domain => $source) {
            $gl = Money::normalize($totals['gl_'.$domain]);
            $difference = Money::subtract($source, $gl);
            $controls[$domain] = ['operational_total' => $source, 'gl_total' => $gl, 'difference' => $difference, 'reconciled' => Money::compare($difference, 0) === 0];
        }

        return ['reconciled' => $controls['output_vat']['reconciled'] && $controls['input_vat']['reconciled'], 'controls' => $controls, 'from' => $from, 'to' => $to,
            'basis' => 'Posted document accounting dates, including linked source reversals. VAT-return eligibility dates are shown separately.', 'url' => '/tax?tab=vat'];
    }

    public function period(string $period, ?string $carried = null, ?string $refund = null): array
    {
        $from = CarbonImmutable::parse($period.'-01')->startOfMonth()->toDateString();
        $to = CarbonImmutable::parse($from)->endOfMonth()->toDateString();
        $rows = $this->formRows();
        $unmapped = [];
        $warnings = [];
        $expression = $this->salesTreatment();
        $sales = $this->sales()->where('i.invoice_date', '>=', $from)->where('i.invoice_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString())->selectRaw('i.document_type, l.vat_rate, '.$expression.' AS treatment, COUNT(*) AS source_count, ROUND(SUM(l.taxable_amount),2) AS net, ROUND(SUM(l.vat_amount),2) AS vat, ROUND(SUM(l.line_total),2) AS gross, SUM(CASE WHEN SUBSTR(i.supply_date,1,7)<>SUBSTR(i.invoice_date,1,7) THEN 1 ELSE 0 END) AS timing_items')
            ->groupBy('i.document_type', 'l.vat_rate', DB::raw($expression))->get();
        foreach ($sales as $g) {
            $box = $this->salesBox($g->treatment, (string) $g->vat_rate, $g->document_type);
            if ($box === -1) {
                continue;
            }
            if (! $box) {
                $unmapped[] = ['book' => 'sales', 'treatment' => $g->treatment, 'rate' => $g->vat_rate, 'count' => (int) $g->source_count, 'net' => Money::normalize($g->net), 'vat' => Money::normalize($g->vat), 'reason' => 'Confirm the legal reporting category; no return box was guessed.'];

                continue;
            }
            $this->addRow($rows, $box, $g->net, $g->source_count, 'sales', $period);
            if (in_array($box, [12, 14, 53, 55], true)) {
                $this->addRow($rows, $box + 1, $g->vat, $g->source_count, 'sales', $period, $box);
            }
            if ($g->timing_items > 0) {
                $warnings[] = ['code' => 'sales_tax_point_review', 'count' => (int) $g->timing_items, 'message' => 'Supply month differs from the posted invoice month. Confirm tax-point/return correction treatment before filing.'];
            }
        }
        // The existing purchase source records the VAT eligibility period on posting.
        $p = $this->purchaseGroups($period, $from, $to);
        foreach ($p as $g) {
            $claimable = Money::add($g->vat, $g->self_assessed);
            if ($g->entry_type === 'reversal') {
                $unmapped[] = ['book' => 'purchases', 'count' => (int) $g->source_count, 'net' => Money::normalize($g->net), 'vat' => Money::normalize($g->deductible), 'reason' => 'Source reversal requires an accountant-confirmed statutory correction/credit-note classification.'];

                continue;
            }
            $box = $this->purchaseBox($g);
            if (! $box) {
                $unmapped[] = ['book' => 'purchases', 'count' => (int) $g->source_count, 'net' => Money::normalize($g->net), 'vat' => Money::normalize($g->deductible), 'reason' => 'Nonrecoverable/partially recoverable or unsupported tax treatment requires confirmation of the official return basis.'];
            } else {
                $this->addRow($rows, $box, $g->net, $g->source_count, 'purchases', $period);
                if (! in_array($box, [31, 32], true)) {
                    $this->addRow($rows, $box + 1, $g->deductible, $g->source_count, 'purchases', $period, $box);
                }
            }
            if ($g->treatment === 'reverse_charge' && Money::compareDecimal($g->vat_rate, 18) === 0) {
                $this->addRow($rows, 28, $g->net, $g->source_count, 'purchases', $period);
                $this->addRow($rows, 29, $g->self_assessed, $g->source_count, 'purchases', $period, 28);
            }
            if ($g->timing_items > 0) {
                $warnings[] = ['code' => 'purchase_tax_point_bridge', 'count' => (int) $g->timing_items, 'message' => 'Purchase VAT eligibility month differs from its GL posting month; use the source timing bridge.'];
            }
        }
        $output = Money::add(...array_map(fn ($n) => $rows[$n]['amount'], [13, 15, 17, 19, 21, 23, 25, 27, 29]));
        $input = Money::add(...array_map(fn ($n) => $rows[$n]['amount'], [36, 38, 40, 42, 44, 46, 48, 50, 52, 54, 56, 58, 60, 62, 64, 66]));
        $rows[30]['amount'] = $output;
        $rows[30]['source_count'] = array_sum(array_map(fn ($n) => $rows[$n]['source_count'], [13, 15, 17, 19, 21, 23, 25, 27, 29]));
        $rows[30]['drill_url'] = '/tax?tab=vat&period='.$period.'&book=output&box=30';
        $rows[67]['amount'] = $input;
        $rows[67]['source_count'] = array_sum(array_map(fn ($n) => $rows[$n]['source_count'], [36, 38, 40, 42, 44, 46, 48, 50, 52, 54, 56, 58, 60, 62, 64, 66]));
        $rows[67]['drill_url'] = '/tax?tab=vat&period='.$period.'&book=input&box=67';
        foreach ([68, 69, 70, 71, 72] as $n) {
            $rows[$n]['amount'] = null;
        }
        if ($carried !== null && $refund !== null) {
            $credit = Money::maximum(0, Money::subtract(Money::add($input, $carried), $output));
            $rows[68]['amount'] = Money::normalize($carried);
            $rows[69]['amount'] = $credit;
            $rows[70]['amount'] = Money::normalize($refund);
            $rows[71]['amount'] = Money::subtract($credit, $refund);
            $rows[72]['amount'] = Money::maximum(0, Money::subtract($output, Money::add($input, $carried)));
            if (Money::compare($refund, $credit) > 0) {
                $warnings[] = ['code' => 'refund_exceeds_credit', 'message' => 'The proposed external refund exceeds the preview credit balance.'];
            }
        } else {
            $warnings[] = ['code' => 'external_carry_forward_unknown', 'message' => 'Prior filed carry-forward and refund choice are external filing facts. They are not inferred from the GL.'];
        }
        $recon = $this->reconciliation($from, $to);
        $legacyReversals = $this->purchases()->where('e.status', 'reversed')->whereNull('e.reversal_date')->count();
        if ($legacyReversals) {
            $warnings[] = ['code' => 'legacy_reversal_date_missing', 'count' => $legacyReversals, 'message' => 'A legacy reversal has no confirmed effective date. Historical tax reconciliation needs review.'];
        }
        $unclassified = $this->unclassifiedSales($from, $to);
        if ($unclassified) {
            $warnings[] = ['code' => 'unclassified_daily_sales', 'count' => $unclassified, 'message' => 'Finalized Daily Sales without an issued tax invoice have no immutable VAT classification. They remain outside the invoice tax book and need review.'];
        }
        $registered = (bool) InvoiceProfile::query()->value('is_vat_registered');
        $missing = TaxCode::query()->where('active', true)->where('rate', '>', 0)->where(fn ($q) => $q->whereNull('input_account_id')->orWhereNull('output_account_id'))->count();
        $incomplete = collect($warnings)->contains('code', 'refund_exceeds_credit') || ! $recon['reconciled'] || $missing || ! $registered || $unmapped || $unclassified || $legacyReversals || collect($warnings)->contains('code', 'sales_tax_point_review');
        if ($incomplete) {
            foreach ([69, 71, 72] as $n) {
                $rows[$n]['amount'] = null;
            }
        }
        foreach ($rows as &$row) {
            $row['complete'] = ! $incomplete;
        }
        unset($row);

        return ['period' => $period, 'from' => $from, 'to' => $to, 'rows' => array_values($rows), 'unmapped' => $unmapped, 'warnings' => $warnings, 'reconciliation' => $recon,
            'vat_registered' => $registered, 'missing_control_mappings' => $missing, 'unclassified_daily_sales' => $unclassified, 'ready_for_review' => ! $incomplete && $recon['reconciled'] && ! $missing,
            'filing_status' => 'PREVIEW_NOT_FILED', 'official_submission_supported' => false, 'certified' => false,
            'qualification' => 'Current ATK TV fields9–72; posted invoice dates and stored purchase eligibility periods. Unverified categories/tax points remain review items. No return is submitted.',
            'source' => 'https://crmm.atk-ks.org/sq/Public/CalculatorDetail?id=8'];
    }

    public function documents(string $period, string $book, int $page = 1, ?int $box = null): array
    {
        $from = $period.'-01';
        $to = CarbonImmutable::parse($from)->endOfMonth()->toDateString();
        if (in_array($book, ['output', 'input'], true)) {
            return $this->controlDocuments($period, $book, $page);
        }
        if ($book === 'sales') {
            $q = $this->sales()->where('i.invoice_date', '>=', $from)->where('i.invoice_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString())->select(['l.id', 'i.id as source_id', 'i.invoice_number as reference', 'i.invoice_date as date', 'i.supply_date', 'i.document_type', 'l.description', 'l.taxable_amount as net', 'l.vat_amount as vat', 'l.line_total as gross', 'l.tax_snapshot'])
                ->orderBy('i.invoice_date')->orderBy('l.id');
        } else {
            $q = $this->purchases()->where(fn ($q) => $q->where('e.vat_period', $period)->orWhere(fn ($v) => $v->where('e.reversal_date', '>=', $from)->where('e.reversal_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString())))
                ->select(['e.id', 'e.id as source_id', 'e.document_number as reference', 'e.invoice_date as date', 'e.received_date', 'e.supply_date', 'e.vat_period', 'e.document_type', 'e.vendor_name as description', 'e.net_amount_eur as net', 'e.import_taxable_base_eur', 'e.deductible_vat_amount_eur as vat', 'e.gross_amount_eur as gross', 'e.tax_snapshot', 'e.reversal_date'])->orderBy('e.invoice_date')->orderBy('e.id');
        }
        if ($box !== null) {
            if ($book === 'sales') {
                $expr = $this->salesTreatment();
                if (in_array($box, [12, 14, 53, 55], true)) {
                    $q->where('l.vat_rate', in_array($box, [12, 53], true) ? 18 : 8)->where('i.document_type', in_array($box, [53, 55], true) ? '=' : '!=', 'credit_note');
                    if (in_array($box, [12, 14], true)) {
                        $q->whereRaw($expr." IN ('taxable','standard')");
                    }
                } elseif (in_array($box, [9, 10, 11], true)) {
                    $q->where('i.document_type', '!=', 'credit_note')->whereRaw($expr.' = ?', [[9 => 'exempt_without_credit', 10 => 'exempt_with_credit', 11 => 'export'][$box]]);
                } else {
                    $q->whereRaw('1=0');
                }
            } else {
                $q->where('e.vat_period', $period);
                if (in_array($box, [31, 32], true)) {
                    $q->where('e.vat_rate', 0)->where('e.asset_treatment', $box === 32 ? 'investment' : 'ordinary');
                } elseif (in_array($box, [16, 18, 28, 35, 37, 39, 41, 43, 45, 47, 49, 65], true)) {
                    $rate = in_array($box, [18, 37, 41, 45, 49], true) ? 8 : 18;
                    $q->where('e.vat_rate', $rate);
                    if ($box !== 28) {
                        $q->whereRaw('ROUND(e.deductible_vat_amount_eur,2)=ROUND(e.vat_amount_eur+e.self_assessed_vat_amount_eur,2)');
                    }
                    if (in_array($box, [16, 18], true)) {
                        $q->where('e.document_type', 'credit_note');
                    } else {
                        $q->where('e.document_type', '!=', 'credit_note');
                        if (in_array($box, [28, 65], true)) {
                            $q->where('e.vat_treatment', 'reverse_charge');
                        } else {
                            $q->where('e.source_type', in_array($box, [35, 37, 39, 41], true) ? 'import' : 'domestic')->where('e.asset_treatment', in_array($box, [39, 41, 47, 49], true) ? 'investment' : 'ordinary');
                        }
                    }
                } else {
                    $q->whereRaw('1=0');
                }
            }
        }
        $result = $q->paginate(50, ['*'], 'page', $page);
        $result->getCollection()->transform(function ($row) use ($book) {
            $row->url = $book === 'sales' ? '/invoices?invoice='.$row->source_id : '/accounting?tab=ap&bill='.$row->source_id;

            return $row;
        });

        return $result->toArray();
    }

    private function controlDocuments(string $period, string $book, int $page): array
    {
        $sales = $this->sales()->where('i.invoice_date', '>=', $period.'-01')->where('i.invoice_date', '<', CarbonImmutable::parse($period.'-01')->addMonth()->toDateString())->whereIn('l.vat_rate', [18, 8])->where('i.document_type', $book === 'output' ? '!=' : '=', 'credit_note');
        if ($book === 'output') {
            $sales->whereRaw($this->salesTreatment()." IN ('taxable','standard')");
        }
        $sales->selectRaw("'sales' AS book,l.id,i.id AS source_id,i.invoice_number AS reference,i.invoice_date AS date,l.description,l.taxable_amount AS net,l.vat_amount AS vat,l.line_total AS gross");
        $purchase = $this->purchases()->where('e.vat_period', $period);
        if ($book === 'output') {
            $purchase->where(fn ($q) => $q->where(fn ($v) => $v->where('e.document_type', 'credit_note')->whereIn('e.vat_rate', [18, 8])->whereRaw('ROUND(e.deductible_vat_amount_eur,2)=ROUND(e.vat_amount_eur+e.self_assessed_vat_amount_eur,2)'))->orWhere(fn ($v) => $v->where('e.document_type', '!=', 'credit_note')->where('e.vat_treatment', 'reverse_charge')->where('e.vat_rate', 18)));
            $amount = "CASE WHEN e.document_type='credit_note' THEN e.deductible_vat_amount_eur ELSE e.self_assessed_vat_amount_eur END";
        } else {
            $purchase->where('e.document_type', '!=', 'credit_note')->whereIn('e.vat_rate', [18, 8])->whereRaw('ROUND(e.deductible_vat_amount_eur,2)=ROUND(e.vat_amount_eur+e.self_assessed_vat_amount_eur,2)')
                ->where(fn ($q) => $q->where(fn ($v) => $v->where('e.source_type', 'domestic')->where('e.vat_treatment', '!=', 'reverse_charge'))->orWhere(fn ($v) => $v->where('e.vat_treatment', 'reverse_charge')->where('e.vat_rate', 18))->orWhere(fn ($v) => $v->where('e.source_type', 'import')->where('e.vat_treatment', 'import_vat')->where('e.import_taxable_base_eur', '>', 0)));
            $amount = 'e.deductible_vat_amount_eur';
        }
        $purchase->selectRaw("'purchases' AS book,e.id,e.id AS source_id,e.document_number AS reference,e.invoice_date AS date,e.vendor_name AS description,CASE WHEN e.vat_treatment='import_vat' THEN COALESCE(e.import_taxable_base_eur,0) ELSE e.net_amount_eur END AS net,$amount AS vat,e.gross_amount_eur AS gross");
        $result = DB::query()->fromSub($sales->unionAll($purchase), 'sources')->orderBy('date')->orderBy('book')->orderBy('id')->paginate(50, ['*'], 'page', $page);
        $result->getCollection()->transform(function ($r) {
            $r->url = $r->book === 'sales' ? '/invoices?invoice='.$r->source_id : '/finance?expense='.$r->source_id;

            return $r;
        });

        return $result->toArray();
    }

    public function unclassifiedSales(string $from, string $to): int
    {
        return DailySale::query()->finalized()->where('sale_date', '>=', $from)->where('sale_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString())->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoices as i')->whereColumn('i.daily_sale_id', 'daily_sales.id')->whereColumn('i.company_id', 'daily_sales.company_id')->whereNotNull('i.issued_at')->where('i.invoice_date','<',CarbonImmutable::parse($to)->addDay()->toDateString())->where('i.status', '!=', 'void'))->count();
    }

    private function sales(): Builder
    {
        return DB::table('invoice_items as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->where('i.company_id', $this->company())->whereNotNull('i.issued_at')->whereNotIn('i.status', ['draft', 'void']);
    }

    private function purchases(): Builder
    {
        return DB::table('expenses as e')->where('e.company_id', $this->company())->whereNull('e.deleted_at')->whereIn('e.status', ['posted', 'reversed'])->whereNotNull('e.posted_at')->where('e.document_type', '!=', 'opening_balance');
    }

    private function company(): int
    {
        return (int) Auth::user()->company_id;
    }

    private function dates(Builder $q, string $field, ?string $from, ?string $to): void
    {
        if ($from) {
            $q->where($field, '>=', $from);
        }if ($to) {
            $q->where($field, '<', CarbonImmutable::parse($to)->addDay()->toDateString());
        }
    }

    private function salesTreatment(): string
    {
        return DB::getDriverName() === 'mysql' ? "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.tax_snapshot,'$.treatment')),l.tax_treatment)" : "COALESCE(json_extract(l.tax_snapshot,'$.treatment'),l.tax_treatment)";
    }

    private function salesBox(string $t, string $rate, string $document): int
    {
        if ($document === 'credit_note') {
            return Money::compareDecimal($rate, 18) === 0 ? 53 : (Money::compareDecimal($rate, 8) === 0 ? 55 : 0);
        }

        return match ($t) {
            'taxable','standard' => Money::compareDecimal($rate, 18) === 0 ? 12 : (Money::compareDecimal($rate, 8) === 0 ? 14 : 0),'exempt_without_credit' => 9,'exempt_with_credit' => 10,'export' => 11,'out_of_scope','non_vat' => -1,default => 0
        };
    }

    private function purchaseBox(object $g): int
    {
        $rate = Money::compareDecimal($g->vat_rate, 18) === 0 ? 18 : (Money::compareDecimal($g->vat_rate, 8) === 0 ? 8 : 0);
        if (! $rate && Money::compare($g->vat, 0) === 0) {
            return $g->asset_treatment === 'investment' ? 32 : 31;
        }
        if (! $g->fully_deductible) {
            return 0;
        }
        if ($g->document_type === 'credit_note') {
            return $rate === 18 ? 16 : ($rate === 8 ? 18 : 0);
        }
        if ($g->treatment === 'reverse_charge') {
            return $rate === 18 ? 65 : 0;
        }
        if ($g->source_type === 'import') {
            if ($g->treatment !== 'import_vat') {
                return 0;
            }

            return $g->asset_treatment === 'investment' ? ($rate === 18 ? 39 : 41) : ($rate === 18 ? 35 : 37);
        }

        return $g->asset_treatment === 'investment' ? ($rate === 18 ? 47 : 49) : ($rate === 18 ? 43 : 45);
    }

    private function purchaseGroups(string $period, string $from, string $to)
    {
        $queries = [];
        foreach ([false, true] as $reversal) {
            $q = $this->purchases();
            if ($reversal) {
                $q->where('e.status', 'reversed')->where('e.reversal_date', '>=', $from)->where('e.reversal_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString());
            } else {
                $q->where('e.vat_period', $period);
            }
            $queries[] = $q->selectRaw('e.document_type,e.source_type,e.asset_treatment,e.vat_treatment AS treatment,e.vat_rate, '.($reversal ? "'reversal'" : "'document'")." AS entry_type, CASE WHEN (e.vat_treatment<>'import_vat' OR e.import_taxable_base_eur>0) AND ROUND(e.deductible_vat_amount_eur,2)=ROUND(e.vat_amount_eur+e.self_assessed_vat_amount_eur,2) THEN 1 ELSE 0 END AS fully_deductible,CASE WHEN e.vat_treatment='import_vat' THEN COALESCE(e.import_taxable_base_eur,0) ELSE e.net_amount_eur END AS net,e.vat_amount_eur AS vat,e.self_assessed_vat_amount_eur AS self_assessed,e.deductible_vat_amount_eur AS deductible, CASE WHEN SUBSTR(e.invoice_date,1,7)<>e.vat_period THEN 1 ELSE 0 END AS timing_items");
        }
        $all = $queries[0]->unionAll($queries[1]);

        return DB::query()->fromSub($all, 'p')->selectRaw('document_type,source_type,asset_treatment,treatment,vat_rate,entry_type,fully_deductible, COUNT(*) AS source_count,ROUND(SUM(net),2) AS net,ROUND(SUM(vat),2) AS vat,ROUND(SUM(self_assessed),2) AS self_assessed,ROUND(SUM(deductible),2) AS deductible,SUM(timing_items) AS timing_items')
            ->groupBy('document_type', 'source_type', 'asset_treatment', 'treatment', 'vat_rate', 'entry_type', 'fully_deductible')->get();
    }

    private function addRow(array &$rows, int $n, mixed $value, int $count, string $book, string $period, ?int $base = null): void
    {
        $rows[$n]['amount'] = Money::add($rows[$n]['amount'], $value);
        $rows[$n]['source_count'] += $count;
        $rows[$n]['drill_url'] = '/tax?tab=vat&period='.$period.'&book='.$book.'&box='.($base ?? $n);
    }

    private function formRows(): array
    {
        $labels = [9 => 'Exempt supplies without credit right', 10 => 'Exempt supplies with credit right', 11 => 'Exports', 12 => 'Taxable supplies18%', 14 => 'Taxable supplies8%', 16 => 'Issued debit/received credit notes18%', 18 => 'Issued debit/received credit notes8%', 20 => 'Received bad-debt invoices/collections18%', 22 => 'Received bad-debt invoices/collections8%', 24 => 'Adjustments increasing VAT18%', 26 => 'Adjustments increasing VAT8%', 28 => 'Reverse-charge purchases18%', 30 => 'Total output VAT', 31 => 'Purchases/imports without VAT', 32 => 'Investment purchases/imports without VAT', 33 => 'Purchases/imports with non-deductible VAT', 34 => 'Investment purchases/imports with non-deductible VAT', 35 => 'Imports18%', 37 => 'Imports8%', 39 => 'Investment imports18%', 41 => 'Investment imports8%', 43 => 'Domestic purchases18%', 45 => 'Domestic purchases8%', 47 => 'Domestic investment purchases18%', 49 => 'Domestic investment purchases8%', 51 => 'Purchases from domestic farmers8%', 53 => 'Received debit/issued credit notes18%', 55 => 'Received debit/issued credit notes8%', 57 => 'Issued bad-debt invoices/payments18%', 59 => 'Issued bad-debt invoices/payments8%', 61 => 'Adjustments decreasing VAT18%', 63 => 'Adjustments decreasing VAT8%', 65 => 'Reverse-charge input credit18%', 67 => 'Total deductible input VAT', 68 => 'Credit carried from previous filed period', 69 => 'Remaining credit balance', 70 => 'Refund requested', 71 => 'Credit for next period', 72 => 'VAT payable'];
        $rows = [];
        for ($n = 9; $n <= 72; $n++) {
            $rows[$n] = ['number' => $n, 'label' => preg_replace('/(?<=[A-Za-z])(?=18%|8%)/', ' ', $labels[$n] ?? ('VAT on field '.($n - 1))), 'amount' => '0.00', 'source_count' => 0, 'drill_url' => null];
        }

        return $rows;
    }
}
