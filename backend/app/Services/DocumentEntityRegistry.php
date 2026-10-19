<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class DocumentEntityRegistry
{
    public const TYPES = [
        'employee'=>['Employee','employees','employees.documents','employee_number','/workforce?employee='],
        'employment-term'=>['EmploymentTerm','employment_terms','employees.compensation','id','/workforce?term='],
        'payroll-run'=>['PayrollRun','payroll_runs','payroll.view','id','/payroll?run='],
        'daily-sale' => ['DailySale','daily_sales','daily_sales.manage','sale_number','/daily-sales?sale='],
        'customer-transaction' => ['CustomerDebtTransaction','customer_debt_transactions','debts.view','reference_number','/customer-debts?transaction='],
        'supplier-payment' => ['PurchaseOrderPayment','purchase_order_payments','purchase_orders.view','reference_number','/purchase-orders?payment='],
        'supplier-allocation' => ['SupplierInvoicePaymentAllocation','supplier_invoice_payment_allocations','supplier_invoices.view','id','/accounting?tab=ap&allocation='],
        'landed-cost' => ['LandedCost','landed_costs','landed_costs.manage','reference_number','/operations-center?tab=landed&landed_cost='],
        'stock-movement' => ['StockMovement','stock_movements','inventory.view','reason','/stock?movement='],
        'financial-transfer' => ['FinancialAccountTransfer','financial_account_transfers','financial_accounts.view','reference_number','/accounting?tab=bank&transfer='],
        'bank-statement' => ['BankStatement','bank_statements','bank_reconciliation.view','file_name','/accounting?tab=bank&statement='],
        'financial-account' => ['FinancialAccount','financial_accounts','financial_accounts.view','name','/accounting?tab=bank&account='],
        'financial-transaction' => ['FinancialAccountTransaction','financial_account_transactions','financial_accounts.view','reference_number','/accounting?tab=bank&transaction='],
        'product' => ['Product', 'products', 'products.manage', 'name', '/products?product='],
        'customer' => ['Customer', 'customers', 'customers.manage', 'name', '/customer-debts?customer='],
        'supplier' => ['Supplier', 'suppliers', 'suppliers.manage', 'name', '/suppliers?supplier='],
        'purchase-request' => ['PurchaseRequest', 'purchase_requests', 'procurement.view', 'request_number', '/procurement?request='],
        'rfq' => ['Rfq', 'rfqs', 'procurement.view', 'rfq_number', '/procurement?rfq='],
        'purchase-order' => ['PurchaseOrder', 'purchase_orders', 'purchase_orders.view', 'po_number', '/purchase-orders?po='],
        'goods-receipt' => ['GoodsReceipt', 'goods_receipts', 'purchase_orders.view', 'receipt_number', '/warehouse-operations?receipt='],
        'quality-inspection' => ['QualityInspection', 'quality_inspections', 'quality.view', 'inspection_number', '/quality?inspection='],
        'supplier-claim' => ['SupplierClaim', 'supplier_claims', 'quality.claims.view', 'claim_number', '/quality?claim='],
        'shipment' => ['Shipment', 'shipments', 'shipments.view', 'tracking_number', '/shipments/my-shipments?shipment='],
        'container' => ['ShipmentContainer', 'shipment_containers', 'shipments.view', 'container_number', '/control-tower?container='],
        'sales-order' => ['SalesOrder', 'sales_orders', 'fulfillment.view', 'order_number', '/fulfillment?order='],
        'delivery' => ['OutboundDispatch', 'outbound_dispatches', 'fulfillment.view', 'reference', '/fulfillment?dispatch='],
        'outbound-return' => ['OutboundReturn', 'outbound_returns', 'fulfillment.view', 'reference', '/fulfillment?return='],
        'return' => ['InventoryReturn', 'inventory_returns', 'returns.view', 'return_number', '/operations-center?return='],
        'invoice' => ['Invoice', 'invoices', 'invoices.manage', 'invoice_number', '/invoices?invoice='],
        'payment' => ['PaymentTransaction', 'payment_transactions', 'payments.process', 'transaction_ref', '/finance?payment='],
        'expense' => ['Expense', 'expenses', 'expenses.manage', 'document_number', '/finance?expense='],
        'journal' => ['JournalEntry', 'journal_entries', 'accounting.journal.view', 'journal_number', '/accounting?journal='],
    ];

    public function resolve(string $type, int $id, bool $authorize = true)
    {
        abort_unless(isset(self::TYPES[$type]) && Auth::user()?->company_id, 404);
        [$model,,$permission] = self::TYPES[$type];
        if ($authorize) {
            abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, $permission), 403, 'You cannot access this business entity.');
        }
        $class = 'App\\Models\\'.$model;

        $record=$class::withoutGlobalScopes()->where('company_id', Auth::user()->company_id)->findOrFail($id);
        if($authorize && $type==='journal' && ($record->source_module==='payroll' || $record->reversalOf?->source_module==='payroll')) abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role,'payroll.view'),403);
        return $record;
    }

    public function describe(string $type, int $id): array
    {
        $entity = $this->resolve($type, $id);
        $config = self::TYPES[$type];
        $url = $config[4].$id;
        if ($type==='employment-term') {
            $url='/workforce?employee='.$entity->employee_id;
        } elseif (in_array($type, ['delivery', 'outbound-return'])) {
            $url = '/fulfillment?order='.$entity->sales_order_id;
        } elseif ($type === 'container') {
            $url = '/control-tower/'.$entity->shipment_id;
        } elseif ($type === 'payment' && $entity->invoice_id) {
            $url = '/invoices?invoice='.$entity->invoice_id;
        }

        return ['type' => $type, 'id' => $id, 'label' => $entity->{$config[3]} ?: ucfirst(str_replace('-', ' ', $type)).' #'.$id, 'url' => $url];
    }

    public function options(string $type, string $term): array
    {
        abort_unless(isset(self::TYPES[$type]), 422);
        [$model,,$permission,$field] = self::TYPES[$type];
        abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, $permission), 403);
        $class = 'App\\Models\\'.$model;
        $q = $class::query();
        // Some operational entities have no display reference; identifiers remain searchable.
        if (Schema::hasColumn(self::TYPES[$type][1], $field)) {
            $q->where($field, 'like', '%'.addcslashes($term, '%_').'%');
        } elseif (ctype_digit($term)) {
            $q->whereKey($term);
        }

        return $q->latest('id')->limit(25)->get()->map(fn ($e) => $this->describe($type,$e->id))->all();
    }
}
