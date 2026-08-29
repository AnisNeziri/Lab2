<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'View Dashboard', 'slug' => 'dashboard.view', 'group' => 'dashboard'],
            ['name' => 'Manage Products', 'slug' => 'products.manage', 'group' => 'inventory'],
            ['name' => 'Delete Products', 'slug' => 'products.delete', 'group' => 'inventory'],
            ['name' => 'Manage Stock', 'slug' => 'stock.manage', 'group' => 'inventory'],
            ['name' => 'View Bin Inventory', 'slug' => 'inventory.view', 'group' => 'inventory'],
            ['name' => 'Manage Physical Counts', 'slug' => 'inventory.counts.manage', 'group' => 'inventory'],
            ['name' => 'Approve Physical Counts', 'slug' => 'inventory.counts.approve', 'group' => 'inventory'],
            ['name' => 'Use Mobile Warehouse', 'slug' => 'warehouse_mobile.use', 'group' => 'inventory'],
            ['name' => 'Manage Warehouses', 'slug' => 'warehouses.manage', 'group' => 'inventory'],
            ['name' => 'View Stock Transfers', 'slug' => 'transfers.view', 'group' => 'inventory'],
            ['name' => 'Manage Stock Transfers', 'slug' => 'transfers.manage', 'group' => 'inventory'],
            ['name' => 'Dispatch Stock Transfers', 'slug' => 'transfers.dispatch', 'group' => 'inventory'],
            ['name' => 'Receive Stock Transfers', 'slug' => 'transfers.receive', 'group' => 'inventory'],
            ['name' => 'Manage Categories', 'slug' => 'categories.manage', 'group' => 'inventory'],
            ['name' => 'Manage Suppliers', 'slug' => 'suppliers.manage', 'group' => 'inventory'],
            ['name' => 'View Supplier Catalogue', 'slug' => 'supplier_catalogue.view', 'group' => 'procurement'],
            ['name' => 'Manage Supplier Catalogue', 'slug' => 'supplier_catalogue.manage', 'group' => 'procurement'],
            ['name' => 'View Replenishment', 'slug' => 'replenishment.view', 'group' => 'procurement'],
            ['name' => 'Create Replenishment Drafts', 'slug' => 'replenishment.manage', 'group' => 'procurement'],
            ['name' => 'Manage Landed Costs', 'slug' => 'landed_costs.manage', 'group' => 'procurement'],
            ['name' => 'View Returns', 'slug' => 'returns.view', 'group' => 'inventory'],
            ['name' => 'Manage Returns', 'slug' => 'returns.manage', 'group' => 'inventory'],
            ['name' => 'Approve Returns', 'slug' => 'returns.approve', 'group' => 'inventory'],
            ['name' => 'View Reports', 'slug' => 'reports.view', 'group' => 'reports'],
            ['name' => 'Manage Invoices', 'slug' => 'invoices.manage', 'group' => 'finance'],
            ['name' => 'Manage Invoice Identity', 'slug' => 'invoice_profile.manage', 'group' => 'finance'],
            ['name' => 'Manage Daily Sales', 'slug' => 'daily_sales.manage', 'group' => 'finance'],
            ['name' => 'Finalize Daily Sales', 'slug' => 'daily_sales.finalize', 'group' => 'finance'],
            ['name' => 'Delete Daily Sales', 'slug' => 'daily_sales.delete', 'group' => 'finance'],
            ['name' => 'Process Payments', 'slug' => 'payments.process', 'group' => 'finance'],
            ['name' => 'View Finance Center', 'slug' => 'finance.view', 'group' => 'finance'],
            ['name' => 'Manage Expenses', 'slug' => 'expenses.manage', 'group' => 'finance'],
            ['name' => 'Export Finance Books', 'slug' => 'finance.export', 'group' => 'finance'],
            ['name' => 'View Money Accounts', 'slug' => 'financial_accounts.view', 'group' => 'finance'],
            ['name' => 'Manage Money Accounts', 'slug' => 'financial_accounts.manage', 'group' => 'finance'],
            ['name' => 'Adjust Money Accounts', 'slug' => 'financial_accounts.adjust', 'group' => 'finance'],
            ['name' => 'View Bank Reconciliation', 'slug' => 'bank_reconciliation.view', 'group' => 'finance'],
            ['name' => 'Manage Bank Statements', 'slug' => 'bank_reconciliation.manage', 'group' => 'finance'],
            ['name' => 'Confirm Bank Reconciliation', 'slug' => 'bank_reconciliation.confirm', 'group' => 'finance'],
            ['name' => 'Manage Customer Debts', 'slug' => 'debts.manage', 'group' => 'finance'],
            ['name' => 'View Customer Debts', 'slug' => 'debts.view', 'group' => 'finance'],
            ['name' => 'Create Customer Debts', 'slug' => 'debts.create', 'group' => 'finance'],
            ['name' => 'Record Debt Payments', 'slug' => 'debts.payments', 'group' => 'finance'],
            ['name' => 'Adjust Customer Debts', 'slug' => 'debts.adjust', 'group' => 'finance'],
            ['name' => 'Manage Customers', 'slug' => 'customers.manage', 'group' => 'finance'],
            ['name' => 'Export Debt Statements', 'slug' => 'debts.export', 'group' => 'finance'],
            ['name' => 'View Purchase Orders', 'slug' => 'purchase_orders.view', 'group' => 'procurement'],
            ['name' => 'Manage Purchase Orders', 'slug' => 'purchase_orders.manage', 'group' => 'procurement'],
            ['name' => 'Record Purchase Order Payments', 'slug' => 'purchase_orders.payments', 'group' => 'procurement'],
            ['name' => 'Receive Purchase Orders', 'slug' => 'purchase_orders.receive', 'group' => 'procurement'],
            ['name' => 'Export Purchase Orders', 'slug' => 'purchase_orders.export', 'group' => 'procurement'],
            ['name' => 'View Supplier Invoices', 'slug' => 'supplier_invoices.view', 'group' => 'procurement'],
            ['name' => 'Manage Supplier Invoices', 'slug' => 'supplier_invoices.manage', 'group' => 'procurement'],
            ['name' => 'Approve Supplier Invoices', 'slug' => 'supplier_invoices.approve', 'group' => 'procurement'],
            ['name' => 'View Shipments', 'slug' => 'shipments.view', 'group' => 'shipments'],
            ['name' => 'Manage Shipment Logistics', 'slug' => 'shipments.manage', 'group' => 'shipments'],
            ['name' => 'Manage Users', 'slug' => 'users.manage', 'group' => 'admin'],
            ['name' => 'View Activity Logs', 'slug' => 'activity.view', 'group' => 'admin'],
            ['name' => 'Manage CMS', 'slug' => 'cms.manage', 'group' => 'content'],
            ['name' => 'Import Data', 'slug' => 'import.execute', 'group' => 'data'],
            ['name' => 'Export Data', 'slug' => 'export.execute', 'group' => 'data'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['slug' => $permission['slug']], $permission);
        }

        $roles = [
            'superadmin' => Permission::pluck('id')->all(),
            'admin' => Permission::pluck('id')->all(),
            'manager' => Permission::whereNotIn('slug', ['users.manage', 'activity.view', 'cms.manage'])->pluck('id')->all(),
            'staff' => Permission::whereIn('slug', [
                'dashboard.view',
                'products.manage',
                'stock.manage',
                'inventory.view',
                'inventory.counts.manage',
                'warehouse_mobile.use',
                'transfers.view',
                'reports.view',
                'invoices.manage',
                'finance.view',
                'financial_accounts.view',
                'daily_sales.manage',
                'debts.view',
                'debts.payments',
                'purchase_orders.view',
                'supplier_catalogue.view',
                'replenishment.view',
                'returns.view',
                'returns.manage',
                'supplier_invoices.view',
                'shipments.view',
                'export.execute',
            ])->pluck('id')->all(),
        ];

        foreach ($roles as $slug => $permissionIds) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst($slug), 'description' => ucfirst($slug).' role']
            );
            $role->permissions()->sync($permissionIds);
            Cache::forget("role_permissions:{$slug}");
        }
    }
}
