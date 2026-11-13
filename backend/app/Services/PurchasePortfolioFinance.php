<?php

namespace App\Services;

use App\Models\{Expense, PurchaseOrder};
use App\Support\{CompanyClock, CompanyCurrency, Money};

/** Reconciles known purchasing exposure for planning. The payable report remains the bill authority. */
final class PurchasePortfolioFinance
{
    public function read(string $currency): array
    {
        abort_unless(app(PurchaseComparisonService::class)->budgetAllowed(), 403);
        $today = CompanyClock::today();
        $readiness = app(PurchasingFinancialEvidence::class)->assess();
        $cash = app(InventoryPlanningInsights::class)->recordedCash($currency);
        // The legacy payable report's carrying columns are EUR. Never relabel them in another currency.
        $baseSupported = $currency === 'EUR' && CompanyCurrency::current() === 'EUR';
        $positions = app(PayableReports::class)->positions($today->toDateString())
            ->whereNotNull('supplier_id');
        $unconvertedBills = (clone $positions)->where('outstanding', '>', 0)->where('base_outstanding', '<=', 0)->count();
        $positions->where('base_outstanding', '>', 0);
        $bills = $baseSupported ? Money::normalize((clone $positions)->sum('base_outstanding')) : null;
        $linked = $baseSupported ? Money::normalize((clone $positions)->whereNotNull('purchase_order_id')->sum('base_outstanding')) : null;
        $unlinked = $baseSupported ? Money::subtract($bills, $linked) : null;
        $due = $baseSupported ? Money::normalize((clone $positions)->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $today->addDays(30)->toDateString())->sum('base_outstanding')) : null;
        $poExposure = 0;
        $alreadyInvoiced = 0;
        $uncertain = 0;
        $counts = [];
        $review = [];
        $qualified = [];
        $possibleUnlinkedOverlap = [];
        $unlinkedSuppliers = (clone $positions)->whereNull('purchase_order_id')->distinct()->pluck('supplier_id')->flip();
        $capture = function (string $reason, $order, $bills, ?string $residual, bool $requiresReview) use (&$counts, &$review, &$qualified) {
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            $target = &$qualified;
            if ($requiresReview) $target = &$review;
            if (count($target) >= 50) return;
            $target[] = ['order_id' => $order->id, 'supplier_id' => $order->supplier_id, 'reference' => $order->po_number, 'url' => '/purchase-orders?po='.$order->id,
                'reason' => $reason, 'currency' => $order->currency, 'due_date' => $order->due_at?->toDateString(),
                'order_materials' => $order->total_amount, 'recorded_po_payments' => $order->total_paid,
                'unbilled_base' => $requiresReview ? null : $residual,
                'possible_unbilled_base' => $requiresReview ? $residual : null,
                'bills' => $bills->take(8)->map(fn ($bill) => ['id' => $bill->id, 'reference' => $bill->document_number,
                    'match_status' => $bill->match_status, 'net' => $bill->net_amount, 'gross' => $bill->gross_amount,
                    'currency' => $bill->currency])->values()->all(), 'bill_count' => $bills->count()];
        };
        $orders = PurchaseOrder::whereNotIn('status', ['draft', 'cancelled'])->whereDate('ordered_at', '<=', $today->toDateString());
        $orders->select(['id', 'po_number', 'supplier_id', 'total_amount', 'total_paid', 'currency', 'exchange_rate', 'exchange_rate_date', 'due_at'])
            ->chunkById(100, function ($chunk) use ($currency, $today, $capture, $unlinkedSuppliers, &$poExposure, &$alreadyInvoiced, &$uncertain, &$possibleUnlinkedOverlap) {
                $invoices = Expense::whereIn('purchase_order_id', $chunk->pluck('id'))->where('status', 'posted')
                    ->whereDate('invoice_date', '<=', $today->toDateString())
                    ->get(['id', 'purchase_order_id', 'supplier_id', 'document_number', 'document_type', 'match_status',
                        'match_summary', 'approved_at', 'currency', 'net_amount', 'gross_amount'])
                    ->groupBy('purchase_order_id');
                $credited = Expense::whereIn('original_expense_id', $invoices->flatten(1)->pluck('id'))
                    ->where('document_type', 'credit_note')->where('status', 'posted')
                    ->whereDate('invoice_date', '<=', $today->toDateString())->pluck('original_expense_id')->flip();
                foreach ($chunk as $order) {
                    $related = $invoices->get($order->id, collect());
                    $rate = $order->currency === $currency ? 1 : $order->exchange_rate;
                    if (!$rate || $rate <= 0 || ($order->currency !== $currency
                        && (!$order->exchange_rate_date || $order->exchange_rate_date->toDateString() > $today->toDateString()))) {
                        $uncertain++;
                        $capture('missing_po_fx', $order, $related, null, true);
                        continue;
                    }
                    $residual = Money::multiply(Money::maximum(0, Money::subtract($order->total_amount, $order->total_paid)), $rate);
                    if ($related->isEmpty()) {
                        if (Money::minor($residual) > 0 && $unlinkedSuppliers->has($order->supplier_id)) {
                            $possibleUnlinkedOverlap[$order->id] = true;
                            $capture('unlinked_bill_same_supplier', $order, $related, $residual, true);
                            continue;
                        }
                        $poExposure += Money::minor($residual);
                        $capture('unbilled', $order, $related, $residual, false);
                        continue;
                    }
                    $sameBasis = $order->currency === $currency && $related->every(fn ($bill) =>
                        $bill->document_type === 'purchase_invoice' && $bill->currency === $order->currency
                        && (int) $bill->supplier_id === (int) $order->supplier_id);
                    $matched = $related->every(fn ($bill) => $bill->match_status === 'matched'
                        || ($bill->match_status === 'approved' && $bill->approved_at && ($bill->match_summary['status'] ?? null) === 'matched'));
                    $net = Money::add(...$related->pluck('net_amount')->all());
                    $reason = match (true) {
                        !$sameBasis => 'invoice_scope_or_currency',
                        !$matched => 'invoice_match_unverified',
                        $related->contains(fn ($bill) => $credited->has($bill->id)) => 'credited_bill',
                        Money::compare($net, $order->total_amount) > 0 || Money::minor($net) < 0 => 'invoice_material_variance',
                        Money::compare($net, $order->total_amount) === 0 => 'fully_billed',
                        Money::minor($order->total_paid) !== 0 => 'part_billed_with_po_payments',
                        $unlinkedSuppliers->has($order->supplier_id) => 'unlinked_bill_same_supplier',
                        default => 'part_billed',
                    };
                    if (!in_array($reason, ['fully_billed', 'part_billed'], true)) {
                        $uncertain++;
                        $capture($reason, $order, $related, null, true);
                        continue;
                    }
                    // If posted matched materials cover the whole PO, its unbilled amount is zero
                    // regardless of PO advances. The payable report owns all actual applications.
                    $residual = Money::subtract($order->total_amount, $net);
                    $alreadyInvoiced += Money::minor($net);
                    $poExposure += Money::minor($residual);
                    $capture($reason, $order, $related, $residual, false);
                }
            });
        $uncertain += count($possibleUnlinkedOverlap);
        $overlapSuppliers = collect($review)->where('reason', 'unlinked_bill_same_supplier')->pluck('supplier_id')->unique();
        if ($overlapSuppliers->isNotEmpty()) {
            $potential = (clone $positions)->whereNull('purchase_order_id')->whereIn('supplier_id', $overlapSuppliers)
                ->orderBy('id')->limit(100)->get(['supplier_id', 'document_number', 'due_date', 'base_outstanding'])->groupBy('supplier_id');
            foreach ($review as &$row) {
                if ($row['reason'] === 'unlinked_bill_same_supplier') {
                    $row['potentially_related_bills'] = $potential->get($row['supplier_id'], collect())->take(5)->values()->all();
                }
            }
            unset($row);
        }
        $readiness['status'] = match (true) {
            $readiness['status'] === 'blocked' => 'blocked',
            !$baseSupported => 'not_evaluated',
            $uncertain > 0 || $unconvertedBills > 0 => 'partial',
            default => 'verified',
        };
        $verified = $readiness['status'] === 'verified';
        $combined = $verified ? Money::add($bills, Money::decimal($poExposure)) : null;
        $sourceRows = $verified || !$baseSupported ? [] : array_map(function ($row) {
            $row['recorded_order_residual'] = Money::maximum(0, Money::subtract($row['order_materials'], $row['recorded_po_payments']));
            $row['unbilled_base'] = null;
            return $row;
        }, $qualified);
        // Positive amounts remain visible as source evidence. A zero from incomplete records is unknown.
        $sourceAmount = fn ($amount) => !$verified && $amount === '0.00' ? null : $amount;
        return $cash + [
            'posted_supplier_bills' => $sourceAmount($bills), 'linked_supplier_bills' => $sourceAmount($linked),
            'unlinked_supplier_bills' => $sourceAmount($unlinked), 'supplier_bills_due_30_days_or_overdue' => $sourceAmount($due),
            'matched_invoiced_materials' => Money::decimal($alreadyInvoiced),
            'known_unbilled_po_exposure' => $verified ? Money::decimal($poExposure) : null,
            'partial_po_source_subtotal' => !$verified && $baseSupported ? $sourceAmount(Money::decimal($poExposure)) : null,
            'evidence_readiness' => $readiness,
            'unresolved_po_overlaps_or_fx' => $uncertain, 'currency_supported_for_payables' => $baseSupported,
            'unconverted_bills' => $unconvertedBills,
            'commitment_evidence' => ['counts' => $counts, 'resolved' => $verified ? $qualified : [], 'partial' => $sourceRows, 'review' => $baseSupported ? $review : [],
                'review_truncated' => $uncertain > count($review), 'resolved_truncated' => array_sum(array_intersect_key($counts, array_flip(['fully_billed', 'part_billed', 'unbilled']))) > count($qualified)],
            'combined_known_obligations' => $combined,
            'planning_cash_after_obligations' => $combined === null || $cash['recorded_cash'] === null || $cash['excluded_currency_accounts']
                ? null : Money::subtract($cash['recorded_cash'], $combined),
            'spendable_cash' => null, 'expected_collections_included' => false,
        ];
    }
}
