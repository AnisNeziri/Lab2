<?php

namespace App\Services\Onboarding;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\OnboardingSession;
use App\Models\Product;
use App\Models\PurchaseOrderPayment;
use App\Models\WarehouseStock;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Read-only controls. Validation never initializes accounts or posts a journal. */
class OnboardingReconciliation
{
    public function report(OnboardingSession $session): array
    {
        $query = $session->rows()->active();
        $counts = (clone $query)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        $totals = array_fill_keys(OnboardingSchema::TOTALS, '0.00');
        $units = [];
        $productValues = [];
        $rowValues = [];
        $productReferences = [];
        $rounding = [];
        foreach ((clone $query)->whereIn('domain', ['inventory', 'receivables', 'customer_advances', 'payables', 'supplier_advances', 'cash_bank'])->whereNotIn('status', ['skipped', 'unvalidated', 'error', 'review'])->orderBy('id')->cursor() as $row) {
            $n = $row->normalized ?? [];
            if ($row->domain === 'inventory') {
                $key = $n['_product_key'] ?? $n['product_ref'];
                $productValues[$key] = (string) BigDecimal::of($productValues[$key] ?? '0')->plus($n['calculated_value'] ?? '0');
                $rowValues[$key] = Money::add($rowValues[$key] ?? '0', $n['calculated_value'] ?? '0');
                $productReferences[$key] = $n['product_ref'];
                $unit = $n['base_unit'];
                $units[$unit] = Money::normalizeDecimal(BigDecimal::of($units[$unit] ?? '0')->plus($n['base_quantity']), 3);
            } elseif (in_array($row->domain, ['receivables', 'customer_advances', 'payables', 'supplier_advances'], true)) {
                $totals[$row->domain] = Money::add($totals[$row->domain], $n['amount']);
            } elseif ($row->domain === 'cash_bank') {
                $key = $n['type'] === 'bank' ? 'bank' : 'cash';
                $totals[$key] = Money::add($totals[$key], $n['amount']);
            }
        }
        foreach ($productValues as $key => $value) {
            $totals['inventory_value'] = Money::add($totals['inventory_value'], $value);
            if (Money::compare($rowValues[$key], $value) !== 0) {
                $rounding[] = ['product_ref' => $productReferences[$key], 'carried_value' => Money::normalize($value), 'journal_rows_value' => $rowValues[$key], 'difference' => Money::subtract($rowValues[$key], $value)];
            }
        }
        $totals['equity'] = Money::subtract(Money::add($totals['inventory_value'], $totals['receivables'], $totals['supplier_advances'], $totals['cash'], $totals['bank']), Money::add($totals['payables'], $totals['customer_advances']));
        $sections = [];
        $blocking = $rounding ? ['inventory_rounding_requires_review'] : [];
        foreach (OnboardingSchema::TOTALS as $key) {
            $expected = $session->expected_totals[$key] ?? null;
            $difference = $expected === null || $expected === '' ? null : Money::subtract($totals[$key], $expected);
            $sections[$key] = ['source' => $expected, 'staged' => $totals[$key], 'difference' => $difference, 'reconciled' => $difference === '0.00'];
            if ($difference !== '0.00') {
                $blocking[] = 'source_total_'.$key;
            }
        }
        $expectedUnits = $session->expected_totals['inventory_units'] ?? null;
        if (! is_array($expectedUnits)) {
            $blocking[] = 'source_inventory_units_required';
        }
        foreach (array_unique([...array_keys($units), ...array_keys($expectedUnits ?? [])]) as $unit) {
            if (! array_key_exists($unit, $expectedUnits ?? []) || Money::compareDecimal($units[$unit] ?? '0', $expectedUnits[$unit]) !== 0) {
                $blocking[] = 'source_quantity_'.$unit;
            }
        }
        if (array_sum($counts) === 0) {
            $blocking[] = 'no_source_rows';
        }
        foreach (['unvalidated', 'error', 'review', 'failed'] as $status) {
            if ($counts[$status] ?? 0) {
                $blocking[] = 'rows_'.$status;
            }
        }
        if ($session->batches()->where('is_active', true)->where('status', '!=', 'staged')->exists()) {
            $blocking[] = 'incomplete_sources';
        }
        $actual = null;
        $actualUnits = null;
        if ($session->posted_at) {
            $actual = $session->status === 'locked' ? ($session->verification['report']['actual'] ?? null) : $this->actual();
            $actualUnits = $session->status === 'locked' ? ($session->verification['report']['actual_inventory_units'] ?? null) : $this->actualUnits();
            if (! is_array($actual) || ! is_array($actualUnits)) {
                $blocking[] = 'missing_locked_evidence';
                $actual = $actual ?? array_fill_keys(OnboardingSchema::TOTALS, '0.00');
                $actualUnits = $actualUnits ?? [];
            }
            foreach (array_unique([...array_keys($units), ...array_keys($actualUnits), ...array_keys($expectedUnits ?? [])]) as $unit) {
                if (Money::compareDecimal($actualUnits[$unit] ?? '0', $expectedUnits[$unit] ?? '0') !== 0) {
                    $blocking[] = 'posted_quantity_'.$unit;
                }
            }
            foreach (OnboardingSchema::TOTALS as $key) {
                $sections[$key]['actual'] = $actual[$key];
                $sections[$key]['actual_difference'] = Money::subtract($actual[$key], $totals[$key]);
                if ($sections[$key]['actual_difference'] !== '0.00') {
                    $blocking[] = 'posted_total_'.$key;
                }
            }
        }

        return ['counts' => $counts, 'totals' => $totals, 'inventory_units' => $units, 'actual_inventory_units' => $actualUnits, 'source_inventory_units' => $expectedUnits, 'sections' => $sections, 'actual' => $actual, 'rounding_details' => $rounding, 'blocking' => array_values(array_unique($blocking)), 'ready_for_review' => ! $blocking];
    }

    public function actualUnits(): array
    {
        $units = [];
        foreach (Product::cursor() as $product) {
            if (Money::compareDecimal($product->quantity, '0') === 0) {
                continue;
            }$units[$product->unit] = Money::normalizeDecimal(BigDecimal::of($units[$product->unit] ?? '0')->plus(Money::normalizeDecimal($product->quantity, 3)), 3);
        }

        return $units;
    }

    public function actual(): array
    {
        $a = array_fill_keys(OnboardingSchema::TOTALS, '0.00');
        foreach (Product::cursor() as $p) {
            $a['inventory_value'] = Money::add($a['inventory_value'], $p->inventory_value ?? Money::multiply($p->quantity, $p->weighted_average_cost ?? $p->purchase_price ?? '0', 6));
        }
        foreach (Customer::cursor() as $c) {
            $a['receivables'] = Money::add($a['receivables'], $c->current_debt);
            $a['customer_advances'] = Money::add($a['customer_advances'], $c->current_credit);
        }
        foreach (Expense::where('status', 'posted')->whereIn('document_type', ['purchase_invoice', 'opening_balance'])->cursor() as $e) {
            $a['payables'] = Money::add($a['payables'], $e->remaining_amount);
        }
        foreach (PurchaseOrderPayment::where('status', 'completed')->cursor() as $p) {
            $a['supplier_advances'] = Money::add($a['supplier_advances'], $p->unallocated_amount);
        }
        foreach (FinancialAccount::cursor() as $account) {
            $key = $account->type === 'bank' ? 'bank' : 'cash';
            $value = $account->opening_balance;
            foreach ($account->transactions()->where('status', 'posted')->cursor() as $t) {
                $value = in_array($t->type, ['inflow', 'transfer_in', 'adjustment_in', 'refund_in'], true) ? Money::add($value, $t->amount) : Money::subtract($value, $t->amount);
            }
            $a[$key] = Money::add($a[$key], $value);
        }
        $a['equity'] = Money::subtract(Money::add($a['inventory_value'], $a['receivables'], $a['supplier_advances'], $a['cash'], $a['bank']), Money::add($a['payables'], $a['customer_advances']));

        return $a;
    }

    public function operationallyEmpty(): bool
    {
        foreach (['stock_movements', 'customer_debt_transactions', 'expenses', 'purchase_order_payments', 'financial_account_transactions', 'journal_entries', 'invoices', 'purchase_orders', 'daily_sales', 'goods_receipts', 'sales_orders'] as $table) {
            if (DB::table($table)->where('company_id', auth()->user()->company_id)->exists()) {
                return false;
            }
        }
        if (Product::where(fn ($q) => $q->where('quantity', '!=', 0)->orWhereNull('quantity')->orWhere('inventory_value', '!=', 0))->exists() || Customer::where(fn ($q) => $q->where('current_debt', '!=', 0)->orWhere('current_credit', '!=', 0)->orWhereNull('current_debt')->orWhereNull('current_credit'))->exists() || FinancialAccount::where('opening_balance', '!=', 0)->exists()) {
            return false;
        }
        if (WarehouseStock::where(fn ($q) => $q->where('quantity', '!=', 0)->orWhere('available_quantity', '!=', 0)->orWhere('reserved_quantity', '!=', 0)->orWhere('damaged_quantity', '!=', 0)->orWhere('quarantine_quantity', '!=', 0)->orWhere('blocked_quantity', '!=', 0))->exists()) {
            return false;
        }

        return true;
    }
}
