<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\ActivityLog;
use App\Models\ExpensePayment;
use App\Models\JournalEntry;
use App\Models\PurchaseOrderPayment;
use App\Models\StockMovement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

class AccountingJournalExperience
{
    public const SOURCES = [
        'daily_sale' => ['DailySale', 'Customer Sale', 'daily_sales.manage', '/daily-sales?sale='],
        'invoice' => ['Invoice', 'Customer Invoice', 'invoices.manage', '/invoices?invoice='],
        'payment_transaction' => ['PaymentTransaction', 'Customer Payment', 'payments.process', '/invoices?payment='],
        'customer_debt_transaction' => ['CustomerDebtTransaction', 'Customer Ledger', 'debts.view', '/customer-debts?transaction='],
        'expense' => ['Expense', 'Supplier Bill / Expense', 'finance.view', '/finance?expense='],
        'expense_payment' => ['ExpensePayment', 'Supplier Payment', 'finance.view', '/finance?expense_payment='],
        'purchase_order_payment' => ['PurchaseOrderPayment', 'Supplier Advance', 'purchase_orders.view', '/purchase-orders?payment='],
        'supplier_payment_allocation' => ['SupplierInvoicePaymentAllocation', 'Supplier Advance Applied', 'supplier_invoices.view', '/operations-center?tab=matching&allocation='],
        'stock_movement' => ['StockMovement', 'Inventory Movement', 'inventory.view', '/stock?movement='],
        'landed_cost' => ['LandedCost', 'Landed Cost', 'landed_costs.manage', '/operations-center?tab=landed&landed_cost='],
        'landed_cost_reclassification' => ['LandedCost', 'Landed Cost Adjustment', 'landed_costs.manage', '/operations-center?tab=landed&landed_cost='],
        'financial_account' => ['FinancialAccount', 'Cash / Bank Opening', 'financial_accounts.view', '/money-accounts?account='],
        'financial_account_transaction' => ['FinancialAccountTransaction', 'Cash / Bank Transaction', 'financial_accounts.view', '/money-accounts?transaction='],
        'financial_account_transfer' => ['FinancialAccountTransfer', 'Cash / Bank Transfer', 'financial_accounts.view', '/money-accounts?transfer='],
        'inventory_return' => ['InventoryReturn', 'Inventory Return', 'returns.view', '/operations-center?return='],
        'journal_entry' => ['JournalEntry', 'Journal Reversal', 'accounting.journal.view', '/accounting?tab=journal&journal='],
    ];

    public function listing(array $f)
    {
        $q = JournalEntry::query()->with(['creator:id,name', 'poster:id,name'])->withCount('lines');
        if (! empty($f['period_id'])) {
            $period = AccountingPeriod::findOrFail($f['period_id']);
            $f['from'] = $period->starts_at->toDateString();
            $f['to'] = $period->ends_at->toDateString();
        }
        $q->when($f['from'] ?? null, fn ($q, $v) => $q->where('posting_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('posting_date', '<', CarbonImmutable::parse($v)->addDay()->toDateString()))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['source_module'] ?? null, fn ($q, $v) => $q->where('source_module', $v))
            ->when($f['account_id'] ?? null, fn ($q, $v) => $q->whereHas('lines', fn ($l) => $l->where('accounting_account_id', $v)))
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where(fn ($a) => $a->where('posted_by', $v)->orWhere('created_by', $v)))
            ->when($f['reference'] ?? null, fn ($q, $v) => $q->where(fn ($a) => $a->where('reference_number', 'like', '%'.$v.'%')->orWhere('journal_number', 'like', '%'.$v.'%')->orWhere('description', 'like', '%'.$v.'%')));
        $page = $q->orderByDesc('posting_date')->orderByDesc('id')->paginate($f['per_page'] ?? 50);
        $periods = AccountingPeriod::query()->where('starts_at', '<=', $page->getCollection()->max('posting_date') ?? now())
            ->where('ends_at', '>=', $page->getCollection()->min('posting_date') ?? now())->get();

        return $page->through(function ($j) use ($periods) {
            $j->setAttribute('source_label', $this->label($j));
            $j->setAttribute('period', $periods->first(fn ($p) => $p->starts_at->lte($j->posting_date) && $p->ends_at->gte($j->posting_date)));

            return $j;
        });
    }

    public function label(JournalEntry $j): string
    {
        return self::SOURCES[$j->source_type][1] ?? match ($j->source_module) {
            'manual' => 'Manual Journal', 'opening', 'onboarding' => 'Opening Balance', default => 'Financial Journal',
        };
    }

    public function detail(JournalEntry $j): array
    {
        $j->load(['lines.account', 'creator:id,name', 'poster:id,name', 'reversal', 'reversalOf']);
        $debit = Money::normalize($j->lines->sum('debit'));
        $credit = Money::normalize($j->lines->sum('credit'));
        $source = null;
        $config = self::SOURCES[$j->source_type] ?? null;
        if ($config && $j->source_id && app(PermissionService::class)->roleHasPermission(Auth::user()->role, $config[2])) {
            $model = 'App\\Models\\'.$config[0];
            $record = $model::query()->find($j->source_id);
            if ($record) {
                $url = $config[3].$record->id;
                if ($j->source_type === 'customer_debt_transaction') {
                    $url = '/customer-debts?customer='.$record->customer_id.'&transaction='.$record->id;
                }
                if ($j->source_type === 'expense_payment') {
                    $url = '/finance?expense='.$record->expense_id;
                }
                if ($j->source_type === 'purchase_order_payment' && $record->purchase_order_id) {
                    $url = '/purchase-orders?po='.$record->purchase_order_id.'&payment='.$record->id;
                }
                if ($j->source_type === 'supplier_payment_allocation') {
                    $url = '/operations-center?tab=matching&supplier_invoice='.$record->expense_id;
                }
                if ($j->source_type === 'payment_transaction' && $record->invoice_id) {
                    $url = '/invoices?invoice='.$record->invoice_id;
                }
                $source = ['label' => $this->label($j), 'id' => $record->id, 'url' => $url];
            }
        }

        return [...$j->toArray(), 'source_label' => $this->label($j), 'source' => $source,
            'period' => AccountingPeriod::query()->whereDate('starts_at', '<=', $j->posting_date)->whereDate('ends_at', '>=', $j->posting_date)->first(),
            'totals' => ['debit' => $debit, 'credit' => $credit, 'difference' => Money::subtract($debit, $credit)],
            'controlled_accounts' => app(ManualJournalService::class)->controlledAccounts($j),
            'audit' => ActivityLog::query()->where('entity', 'JournalEntry')->where('entity_id', $j->id)->with('user:id,name')->latest('created_at')->limit(50)->get(),
            'corrections' => JournalEntry::query()->where('correction_of_id', $j->id)->get(['id', 'journal_number', 'status']),
            'source_owns_correction' => $j->source_module !== 'manual'];
    }

    public function sourceLinks(string $type, int $id): array
    {
        $record = app(DocumentEntityRegistry::class)->resolve($type, $id);
        $sourceType = match ($type) {
            'invoice' => 'invoice', 'payment' => 'payment_transaction', 'expense' => 'expense', 'journal' => 'journal_entry', default => null,
        };
        $q = JournalEntry::query()->where(function ($q) use ($type, $id, $record, $sourceType) {
            $q->whereRaw('1=0');
            if ($sourceType) {
                $q->orWhere(fn ($x) => $x->where('source_type', $sourceType)->where('source_id', $id));
            }
            if ($type === 'goods-receipt') {
                $q->orWhere(fn ($x) => $x->where('source_type', 'stock_movement')->whereIn('source_id', StockMovement::where('source_type', 'goods_receipt')->where('source_id', $id)->select('id')));
            }
            if ($type === 'purchase-order') {
                $q->orWhere(fn ($x) => $x->where('source_type', 'purchase_order_payment')->whereIn('source_id', PurchaseOrderPayment::where('purchase_order_id', $id)->select('id')));
            }
            if ($type === 'expense') {
                $q->orWhere(fn ($x) => $x->where('source_type', 'expense_payment')->whereIn('source_id', ExpensePayment::where('expense_id', $id)->select('id')));
            }
            if ($type === 'customer') {
                $q->orWhere(fn ($x) => $x->where('source_type', 'customer_debt_transaction')->whereIn('source_id', $record->debtTransactions()->select('id')));
            }
        });

        return $q->orderByDesc('posting_date')->limit(20)->get(['id', 'journal_number', 'posting_date', 'status', 'total_debit', 'source_type', 'source_module'])
            ->map(fn ($j) => [...$j->toArray(), 'source_label' => $this->label($j), 'url' => '/accounting?tab=journal&journal='.$j->id])->all();
    }
}
