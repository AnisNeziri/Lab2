<?php

namespace App\Services;

use App\Models\{Expense, PurchaseOrder, PurchaseOrderChange, PurchaseOrderPayment};
use App\Support\CompanyCurrency;
use Illuminate\Support\Facades\DB;

/** Tenant-scoped, live checks for purchasing. Installation certification is separate. */
final class PurchasingFinancialEvidence
{
    public function assess(): array
    {
        $company = auth()->user()?->company;
        abort_unless($company, 403);
        $reconciliation = app(AccountingService::class)->reconciliation(false);
        $controls = array_keys(array_filter($reconciliation['rows'], fn ($row) => !$row['reconciled']));
        $postedBills = Expense::where('status', 'posted')->whereIn('document_type', ['purchase_invoice', 'opening_balance', 'credit_note']);
        $base = CompanyCurrency::forCompanyId((int) $company->id);
        $issues = [
            'orphan_payments' => $this->orphanPayments()->count(),
            'orphan_order_history' => $this->orphanHistory()->count(),
            'orphan_order_lines' => $this->orphanLines((int) $company->id)->count(),
            'order_chronology' => $this->historyBeforeCreation()->count(),
            'supplier_payment_fx' => $this->paymentsWithoutCurrencyEvidence()->count(),
            'unattributed_orders' => PurchaseOrder::whereNotIn('status', ['draft', 'cancelled'])->whereDoesntHave('supplier')->count(),
            'unattributed_bills' => (clone $postedBills)->whereDoesntHave('supplier')->count(),
            'orphan_bills' => (clone $postedBills)->whereNotNull('purchase_order_id')
                ->whereDoesntHave('purchaseOrder')->count(),
            'supplier_bill_fx' => (clone $postedBills)->where(fn ($q) => $q
                ->whereNull('currency')->orWhere('currency', '')
                ->orWhere(fn ($q) => $q->where('currency', '!=', $base)
                    ->where(fn ($q) => $q->whereNull('exchange_rate')->orWhere('exchange_rate', '<=', 0)
                        ->orWhereNull('exchange_rate_date')->orWhereColumn('exchange_rate_date', '>', 'invoice_date'))))->count(),
        ];

        return [
            'status' => $controls || array_sum($issues) ? 'blocked' : 'verified',
            'unreconciled_controls' => $controls,
            'opening_finalized' => (bool) $company->accounting_opening_finalized_at,
            'issues' => $issues,
        ];
    }

    public function reviewSources(): array
    {
        abort_unless(auth()->user()?->company_id
            && app(PermissionService::class)->roleHasPermission(auth()->user()->role, 'accounting.integrity.view'), 403);

        return [
            'status' => 'source_required',
            'orphan_payments' => $this->orphanPayments()->orderBy('id')->limit(20)
                ->get(['id', 'purchase_order_id', 'reference_number', 'amount', 'currency', 'payment_date'])
                ->makeHidden(['allocated_amount', 'unallocated_amount', 'allocation_status']),
            'orphan_order_history' => $this->orphanHistory()->orderBy('id')->limit(20)
                ->get(['id', 'purchase_order_id', 'action', 'created_at']),
            'orphan_order_lines' => $this->orphanLines((int) auth()->user()->company_id)->orderBy('id')->limit(20)
                ->get(['id', 'purchase_order_id', 'product_id']),
            'history_before_creation' => $this->historyBeforeCreation()->with('purchaseOrder:id,po_number')
                ->orderBy('purchase_order_changes.id')->limit(20)
                ->get(['purchase_order_changes.id', 'purchase_order_changes.purchase_order_id', 'purchase_order_changes.created_at', 'creation.created_at as creation_recorded_at'])
                ->map(fn ($change) => [
                    'id' => $change->id,
                    'order_id' => $change->purchase_order_id,
                    'reference' => $change->purchaseOrder?->po_number,
                    'history_recorded_at' => $change->created_at?->toDateString(),
                    'creation_recorded_at' => substr($change->creation_recorded_at, 0, 10),
                ]),
        ];
    }

    private function orphanPayments()
    {
        return PurchaseOrderPayment::whereNotNull('purchase_order_id')->whereDoesntHave('purchaseOrder');
    }

    private function orphanHistory()
    {
        return PurchaseOrderChange::whereDoesntHave('purchaseOrder');
    }

    private function orphanLines(int $companyId)
    {
        return DB::table('purchase_order_items')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('products')
                ->whereColumn('products.id', 'purchase_order_items.product_id')->where('products.company_id', $companyId))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('purchase_orders')
                ->whereColumn('purchase_orders.id', 'purchase_order_items.purchase_order_id')->where('purchase_orders.company_id', $companyId));
    }

    private function historyBeforeCreation()
    {
        $creation = PurchaseOrderChange::query()->where('action', 'created')
            ->selectRaw('company_id, purchase_order_id, MIN(created_at) as created_at')->groupBy('company_id', 'purchase_order_id');

        return PurchaseOrderChange::query()->joinSub($creation, 'creation', fn ($join) => $join
            ->on('creation.company_id', '=', 'purchase_order_changes.company_id')
            ->on('creation.purchase_order_id', '=', 'purchase_order_changes.purchase_order_id'))
            ->whereRaw('DATE(purchase_order_changes.created_at) < DATE(creation.created_at)');
    }

    private function paymentsWithoutCurrencyEvidence()
    {
        $base = CompanyCurrency::forCompanyId((int) auth()->user()->company_id);

        return PurchaseOrderPayment::where('status', 'completed')->where(fn ($q) => $q
            ->whereNull('currency')->orWhere('currency', '')
            ->orWhere(fn ($q) => $q->where('currency', '!=', $base)
                ->where(fn ($q) => $q->whereNull('exchange_rate')->orWhere('exchange_rate', '<=', 0)
                    ->orWhereNull('exchange_rate_date')->orWhereColumn('exchange_rate_date', '>', 'payment_date'))));
    }
}
