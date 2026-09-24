<?php

namespace App\Services;

use App\Models\DailySale;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTransaction;
use App\Models\Invoice;
use App\Models\InventoryReturn;
use App\Models\JournalEntry;
use App\Models\LandedCost;
use App\Models\PaymentTransaction;
use App\Models\PurchaseOrderPayment;
use App\Models\StockMovement;
use App\Models\SupplierInvoicePaymentAllocation;
use App\Support\CompanyCurrency;
use App\Support\Money;

/**
 * Translates completed operational facts into GL consequences. It never
 * changes an operational document or stock quantity.
 */
class OperationalAccountingService
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function postDailySale(DailySale $sale): JournalEntry
    {
        $sale->loadMissing('items');
        $existing = JournalEntry::query()->where('source_module', 'sales')
            ->where('source_type', 'daily_sale')->where('source_id', $sale->id)
            ->where('status', 'posted')->latest('id')->first();
        if ($existing) return $existing;

        $revision = JournalEntry::query()->where('source_module', 'sales')
            ->where('source_type', 'daily_sale')->where('source_id', $sale->id)->count() + 1;
        $total = Money::normalize($sale->total_amount);

        return $this->accounting->postMapped(
            'sales', 'daily_sale', $sale->id, "daily-sale:{$sale->id}:rev:{$revision}",
            $sale->sale_date->toDateString(), 'Daily sale '.$sale->sale_number,
            [
                $this->settlementLine(null, 'cash', 'debit', $total),
                ['mapping' => 'sales_revenue', 'credit' => $total],
            ],
            CompanyCurrency::forCompanyId($sale->company_id), 1,
        );
    }

    public function reverseDailySale(DailySale $sale, string $reason): ?JournalEntry
    {
        $entry = JournalEntry::query()->where('source_module', 'sales')
            ->where('source_type', 'daily_sale')->where('source_id', $sale->id)
            ->where('status', 'posted')->latest('id')->first();

        return $entry ? $this->accounting->reverse($entry, now()->toDateString(), $reason) : null;
    }

    public function postInvoice(Invoice $invoice): ?JournalEntry
    {
        if ($invoice->daily_sale_id && JournalEntry::query()->where('source_module', 'sales')
            ->where('source_type', 'daily_sale')->where('source_id', $invoice->daily_sale_id)
            ->whereIn('status', ['posted', 'reversed'])->exists()) {
            return null;
        }

        $total = Money::normalize($invoice->grand_total ?: $invoice->total_amount);
        $vat = Money::normalize($invoice->vat_total ?: 0);
        $revenue = Money::subtract($total, $vat);
        $lines = [['mapping' => 'accounts_receivable', 'debit' => $total]];
        if (Money::compare($revenue, '0') > 0) $lines[] = ['mapping' => 'sales_revenue', 'credit' => $revenue];
        if (Money::compare($vat, '0') > 0) $lines[] = ['mapping' => 'output_vat', 'credit' => $vat];

        return $this->accounting->postMapped(
            'sales', 'invoice', $invoice->id, 'invoice:'.$invoice->id,
            $invoice->invoice_date->toDateString(), 'Invoice '.$invoice->invoice_number,
            $lines, $invoice->currency ?: CompanyCurrency::forCompanyId($invoice->company_id), 1,
        );
    }

    public function creditInvoice(Invoice $credit, Invoice $original, string $reason): ?JournalEntry
    {
        if ($original->daily_sale_id && JournalEntry::query()->where('source_module', 'sales')
            ->where('source_type', 'daily_sale')->where('source_id', $original->daily_sale_id)->exists()) {
            return null;
        }
        return $this->accounting->reverseSource('sales', 'invoice:'.$original->id,
            $credit->invoice_date->toDateString(), 'Credit note '.$credit->invoice_number.': '.$reason);
    }

    public function postInvoicePayment(PaymentTransaction $payment): JournalEntry
    {
        $payment->loadMissing('invoice');
        $amount = Money::normalize($payment->amount);
        $entry = $this->accounting->postMapped(
            'sales', 'invoice_payment', $payment->id, 'invoice-payment:'.$payment->id,
            $payment->payment_date->toDateString(), 'Invoice payment '.$payment->invoice->invoice_number,
            [
                $this->settlementLine($payment->financial_account_id, $this->cashMapping($payment->payment_method), 'debit', $amount),
                ['mapping' => 'accounts_receivable', 'credit' => $amount],
            ], CompanyCurrency::forCompanyId($payment->company_id), 1,
        );
        $this->linkFinancialTransaction($payment->financial_account_transaction_id, $entry);
        return $entry;
    }

    public function postStockMovement(StockMovement $movement): ?JournalEntry
    {
        if (! $movement->affects_company_quantity || $movement->cost_total === null) return null;
        $amount = Money::normalize(abs((float) $movement->cost_total));
        if (Money::compare($amount, '0') <= 0) return null;

        $lines = match ($movement->movement_code) {
            'purchase_receipt', 'damage_received' => [
                ['mapping' => 'inventory', 'debit' => $amount],
                ['mapping' => 'goods_receipt_clearing', 'credit' => $amount],
            ],
            'daily_sale', 'invoice_sale' => [
                ['mapping' => 'cost_of_goods_sold', 'debit' => $amount],
                ['mapping' => 'inventory', 'credit' => $amount],
            ],
            'daily_sale_reversal', 'invoice_credit', 'customer_return' => [
                ['mapping' => 'inventory', 'debit' => $amount],
                ['mapping' => 'cost_of_goods_sold', 'credit' => $amount],
            ],
            'supplier_return' => [
                ['mapping' => 'goods_receipt_clearing', 'debit' => $amount],
                ['mapping' => 'inventory', 'credit' => $amount],
            ],
            'damage_writeoff', 'internal_use', 'sample' => [
                ['mapping' => 'inventory_loss_damage', 'debit' => $amount],
                ['mapping' => 'inventory', 'credit' => $amount],
            ],
            'manual_adjustment_in', 'import_adjustment_in', 'legacy_stock_in' => [
                ['mapping' => 'inventory', 'debit' => $amount],
                ['mapping' => 'inventory_adjustments', 'credit' => $amount],
            ],
            'manual_adjustment_out', 'import_adjustment_out', 'legacy_stock_out', 'stock_count', 'reconciliation_adjustment' => $movement->type === 'in'
                ? [['mapping' => 'inventory', 'debit' => $amount], ['mapping' => 'inventory_adjustments', 'credit' => $amount]]
                : [['mapping' => 'inventory_adjustments', 'debit' => $amount], ['mapping' => 'inventory', 'credit' => $amount]],
            'opening_balance' => [
                ['mapping' => 'inventory', 'debit' => $amount],
                ['mapping' => 'equity', 'credit' => $amount],
            ],
            default => null,
        };
        if ($lines === null) return null;

        $date = ($movement->occurred_at ?: $movement->created_at)->toDateString();
        $entry = $this->accounting->postMapped(
            'inventory', 'stock_movement', $movement->id, 'stock-movement:'.$movement->id,
            $date, 'Inventory '.$movement->movement_code.' · movement #'.$movement->id,
            $lines, CompanyCurrency::forCompanyId($movement->company_id), 1,
        );
        app(BusinessEventService::class)->record('inventory.accounting_posted', $movement, (string) $movement->id, [
            'journal_entry_id' => $entry->id, 'movement_code' => $movement->movement_code, 'amount' => $amount,
        ], 'inventory-accounting:'.$movement->id);
        return $entry;
    }

    public function postLandedCost(LandedCost $landedCost): JournalEntry
    {
        $landedCost->loadMissing('accountingEntries');
        $inventory = Money::normalize($landedCost->accountingEntries->sum('inventory_adjustment_amount'));
        $cogs = Money::normalize($landedCost->accountingEntries->sum('cogs_adjustment_amount'));
        $total = Money::add($inventory, $cogs);
        $lines = [];
        if (Money::compare($inventory, '0') > 0) $lines[] = ['mapping' => 'inventory', 'debit' => $inventory];
        if (Money::compare($cogs, '0') > 0) $lines[] = ['mapping' => 'cost_of_goods_sold', 'debit' => $cogs];
        $lines[] = ['mapping' => 'goods_receipt_clearing', 'credit' => $total];

        $entry = $this->accounting->postMapped(
            'landed_cost', 'landed_cost', $landedCost->id, 'landed-cost:'.$landedCost->id,
            $landedCost->posted_at->toDateString(), 'Landed cost '.$landedCost->reference_number,
            $lines, $landedCost->currency, $landedCost->exchange_rate_to_base,
        );
        app(BusinessEventService::class)->record('landed_cost.accounting_posted', $landedCost, $landedCost->reference_number, [
            'journal_entry_id' => $entry->id, 'inventory_amount' => $inventory, 'cogs_amount' => $cogs,
        ], 'landed-cost-accounting:'.$landedCost->id);
        return $entry;
    }

    public function reverseLandedCost(LandedCost $landedCost, string $reason, string $date, string $inventoryReclassification): JournalEntry
    {
        $reversal = $this->accounting->reverseSource(
            'landed_cost', 'landed-cost:'.$landedCost->id, $date, $reason,
        );
        if (! $reversal) {
            throw new \LogicException('The posted landed-cost journal could not be found for reversal.');
        }

        $difference = Money::normalize($inventoryReclassification);
        if (Money::compare($difference, '0') !== 0) {
            $amount = Money::normalize(abs((float) $difference));
            $inventoryIncreased = Money::compare($difference, '0') < 0;
            $this->accounting->postMapped(
                'landed_cost', 'landed_cost_reclassification', $landedCost->id,
                'landed-cost:'.$landedCost->id.':reclassification', $date,
                'Landed-cost reversal inventory/COGS reclassification · '.$reason,
                $inventoryIncreased
                    ? [['mapping' => 'inventory', 'debit' => $amount], ['mapping' => 'cost_of_goods_sold', 'credit' => $amount]]
                    : [['mapping' => 'cost_of_goods_sold', 'debit' => $amount], ['mapping' => 'inventory', 'credit' => $amount]],
                CompanyCurrency::forCompanyId($landedCost->company_id), 1,
            );
        }

        return $reversal;
    }

    public function postPurchaseOrderPayment(PurchaseOrderPayment $payment): JournalEntry
    {
        $amount = Money::normalize($payment->amount_eur);
        $entry = $this->accounting->postMapped(
            'supplier_accounting', 'purchase_order_payment', $payment->id, 'purchase-order-payment:'.$payment->id,
            $payment->payment_date->toDateString(), 'Supplier advance/payment for PO #'.$payment->purchase_order_id,
            [
                ['mapping' => 'supplier_advances', 'debit' => $amount],
                $this->settlementLine($payment->financial_account_id, $this->cashMapping($payment->payment_method), 'credit', $amount),
            ], $payment->currency, $payment->exchange_rate,
        );
        $this->linkFinancialTransaction($payment->financial_account_transaction_id, $entry);
        return $entry;
    }

    public function postSupplierAdvanceApplication(SupplierInvoicePaymentAllocation $allocation, string $sourceKey): JournalEntry
    {
        $allocation->loadMissing(['payment', 'expense']);
        $baseAmount = Money::multiply($allocation->amount, $allocation->payment->exchange_rate ?: 1);
        return $this->accounting->postMapped(
            'supplier_accounting', 'supplier_payment_allocation', $allocation->id, $sourceKey,
            $allocation->allocated_at->toDateString(), 'Apply supplier advance to '.$allocation->expense->document_number,
            [
                ['mapping' => 'accounts_payable', 'debit' => $baseAmount],
                ['mapping' => 'supplier_advances', 'credit' => $baseAmount],
            ], $allocation->payment->currency, $allocation->payment->exchange_rate,
        );
    }

    public function postInventoryReturnRefund(InventoryReturn $return): JournalEntry
    {
        $amount = Money::normalize($return->financial_amount);
        $financial = FinancialAccount::query()->findOrFail($return->financial_account_id);
        $cashAccount = $this->accounting->ensureFinancialAccount($financial);
        $customerReturn = $return->type === 'customer';
        $entry = $this->accounting->postMapped(
            'returns', 'inventory_return', $return->id, 'inventory-return-refund:'.$return->id,
            now()->toDateString(), ($customerReturn ? 'Customer refund ' : 'Supplier refund ').$return->return_number,
            $customerReturn
                ? [['mapping' => 'sales_revenue', 'debit' => $amount], ['accounting_account_id' => $cashAccount->id, 'credit' => $amount]]
                : [['accounting_account_id' => $cashAccount->id, 'debit' => $amount], ['mapping' => 'supplier_advances', 'credit' => $amount]],
            CompanyCurrency::forCompanyId($return->company_id), 1,
        );
        $this->linkFinancialTransaction($return->financial_account_transaction_id, $entry);
        return $entry;
    }

    public function reverseSource(string $module, string $key, string $reason): ?JournalEntry
    {
        return $this->accounting->reverseSource($module, $key, now()->toDateString(), $reason);
    }

    private function settlementLine(?int $financialAccountId, string $mapping, string $side, string $amount): array
    {
        if ($financialAccountId) {
            $financial = FinancialAccount::query()->findOrFail($financialAccountId);
            return ['accounting_account_id' => $this->accounting->ensureFinancialAccount($financial)->id, $side => $amount];
        }
        return ['mapping' => $mapping, $side => $amount];
    }

    private function cashMapping(?string $method): string
    {
        return in_array($method, ['bank', 'bank_transfer', 'card', 'cheque'], true) ? 'bank' : 'cash';
    }

    private function linkFinancialTransaction(?int $transactionId, JournalEntry $entry): void
    {
        if ($transactionId) FinancialAccountTransaction::query()->whereKey($transactionId)->update(['journal_entry_id' => $entry->id]);
    }
}
