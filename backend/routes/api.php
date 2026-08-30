<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankReconciliationController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CmsController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DailySaleController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\FinancialAccountController;
use App\Http\Controllers\Api\GlobalMapController;
use App\Http\Controllers\Api\GoodsReceiptController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceProfileController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InventoryCountController;
use App\Http\Controllers\Api\InventoryReturnController;
use App\Http\Controllers\Api\LandedCostController;
use App\Http\Controllers\Api\MobileWarehouseController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductSupplierController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\ReplenishmentController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\ShipmentLogisticsController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\SuperadminController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierInvoiceController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\WarehouseLayoutController;
use App\Http\Controllers\Api\WarehouseOperationsController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes(['middleware' => ['auth.token']]);

Route::get('/status', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Inventory API is running',
    ]);
});

Route::get('/cms/published', [CmsController::class, 'published']);
Route::get('/cms/{slug}', [CmsController::class, 'show']);

Route::middleware('throttle:auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/verify-email', [EmailVerificationController::class, 'verify']);
    Route::post('/verify-email/resend', [EmailVerificationController::class, 'resend']);
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);
});

Route::middleware('auth.token')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::get('/system/mode', [SystemController::class, 'mode']);

    Route::middleware('password.changed')->group(function () {
        Route::middleware('company.context')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'index']);
            Route::get('/dashboard/sales-analytics', [DashboardController::class, 'salesAnalytics']);
            Route::get('/dashboard/activity-feed', [DashboardController::class, 'activityFeed']);
            Route::get('/dashboard/low-stock-alerts', [DashboardController::class, 'lowStockAlerts']);
            Route::get('/reports', [ReportController::class, 'index']);
            Route::get('/settings/preferences', [SettingsController::class, 'show']);
            Route::put('/settings/preferences', [SettingsController::class, 'update']);

            Route::get('/tracking/vessels', [GlobalMapController::class, 'vessels'])->middleware('permission:shipments.view');
            Route::get('/tracking/vessels/{vesselId}', [GlobalMapController::class, 'show'])->middleware('permission:shipments.view');
            Route::get('/tracking/metrics', [GlobalMapController::class, 'metrics'])->middleware('permission:shipments.view');
            Route::get('/tracking/ports', [GlobalMapController::class, 'ports'])->middleware('permission:shipments.view');
            Route::get('/shipments/ports', [ShipmentController::class, 'ports'])->middleware('permission:shipments.view');
            Route::get('/shipments/alerts', [ShipmentController::class, 'alerts'])->middleware('permission:shipments.view');
            Route::post('/shipments/alerts/clear', [ShipmentController::class, 'clearAlerts'])->middleware('permission:shipments.view');
            Route::post('/shipments/validate', [ShipmentController::class, 'validateTracking'])->middleware('permission:shipments.manage');
            Route::post('/shipments/track', [ShipmentController::class, 'track'])->middleware('permission:shipments.manage');
            Route::post('/shipments/vessels/lookup', [ShipmentController::class, 'lookupVessel'])->middleware('permission:shipments.manage');
            Route::post('/shipments/ais', [ShipmentController::class, 'storeAis'])->middleware('permission:shipments.manage');
            Route::get('/shipments', [ShipmentController::class, 'index'])->middleware('permission:shipments.view');
            Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])->middleware('permission:shipments.view');
            Route::post('/shipments/{shipment}/refresh', [ShipmentController::class, 'refresh'])->middleware('permission:shipments.manage');
            Route::post('/shipments/{shipment}/save', [ShipmentController::class, 'save'])->middleware('permission:shipments.manage');
            Route::post('/shipments/{shipment}/favorite', [ShipmentController::class, 'favorite'])->middleware('permission:shipments.manage');
            Route::post('/shipments/{shipment}/archive', [ShipmentController::class, 'archive'])->middleware('permission:shipments.manage');
            Route::post('/shipments/{shipment}/restore', [ShipmentController::class, 'restore'])->middleware('permission:shipments.manage');
            Route::delete('/shipments/{shipment}', [ShipmentController::class, 'destroy'])->middleware('permission:shipments.manage');
            Route::get('/shipments/{shipment}/history', [ShipmentController::class, 'history'])->middleware('permission:shipments.view');
            Route::put('/shipments/{shipment}/logistics', [ShipmentLogisticsController::class, 'update'])->middleware('permission:shipments.manage');
            Route::post('/shipments/{shipment}/documents', [ShipmentLogisticsController::class, 'uploadDocument'])->middleware('permission:shipments.manage');
            Route::get('/shipments/{shipment}/documents/{document}', [ShipmentLogisticsController::class, 'downloadDocument'])->middleware('permission:shipments.view');
            Route::delete('/shipments/{shipment}/documents/{document}', [ShipmentLogisticsController::class, 'deleteDocument'])->middleware('permission:shipments.manage');

            Route::get('/search', [SearchController::class, 'index'])->middleware('permission:dashboard.view');

            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
            Route::post('/notifications/clear', [NotificationController::class, 'clear']);

            Route::get('/export/{list}', [ExportController::class, 'show'])->middleware('permission:export.execute');
            Route::post('/import/{list}', [ImportController::class, 'import'])->middleware('permission:import.execute');
            Route::get('/backup/modules', [ExportController::class, 'backupModules'])->middleware('permission:export.execute');
            Route::post('/backup/export', [ExportController::class, 'backup'])->middleware(['role:admin', 'permission:export.execute']);
            Route::post('/backup/import', [ImportController::class, 'backup'])->middleware(['role:admin', 'permission:import.execute']);

            Route::get('/stock-movements/export', [StockMovementController::class, 'export']);
            Route::get('/stock-movements', [StockMovementController::class, 'index']);
            Route::post('/stock-movements', [StockMovementController::class, 'store'])->middleware('permission:stock.manage');

            Route::get('/inventory/locator', [InventoryController::class, 'locator'])->middleware('permission:inventory.view');
            Route::get('/inventory/products/{product}', [InventoryController::class, 'product'])->middleware('permission:inventory.view');
            Route::get('/inventory/expiring', [InventoryController::class, 'expiring'])->middleware('permission:inventory.view');
            Route::get('/inventory/products/{product}/fefo', [InventoryController::class, 'fefo'])->middleware('permission:inventory.view');
            Route::post('/inventory/bin-transfer', [InventoryController::class, 'moveBin'])->middleware('permission:stock.manage');
            Route::get('/mobile-warehouse/bootstrap', [MobileWarehouseController::class, 'bootstrap'])->middleware('permission:warehouse_mobile.use');
            Route::get('/mobile-warehouse/lookup', [MobileWarehouseController::class, 'lookup'])->middleware('permission:warehouse_mobile.use');
            Route::post('/mobile-warehouse/receive', [MobileWarehouseController::class, 'receive'])->middleware(['permission:warehouse_mobile.use', 'permission:purchase_orders.receive']);
            Route::post('/mobile-warehouse/move', [MobileWarehouseController::class, 'move'])->middleware(['permission:warehouse_mobile.use', 'permission:stock.manage']);
            Route::post('/mobile-warehouse/count', [MobileWarehouseController::class, 'count'])->middleware(['permission:warehouse_mobile.use', 'permission:inventory.counts.manage']);
            Route::post('/mobile-warehouse/pick', [MobileWarehouseController::class, 'pick'])->middleware(['permission:warehouse_mobile.use', 'permission:stock.manage']);
            Route::get('/inventory-counts', [InventoryCountController::class, 'index'])->middleware('permission:inventory.counts.manage');
            Route::post('/inventory-counts', [InventoryCountController::class, 'store'])->middleware('permission:inventory.counts.manage');
            Route::get('/inventory-counts/{inventoryCount}', [InventoryCountController::class, 'show'])->middleware('permission:inventory.counts.manage');
            Route::post('/inventory-counts/{inventoryCount}/record', [InventoryCountController::class, 'record'])->middleware('permission:inventory.counts.manage');
            Route::post('/inventory-counts/{inventoryCount}/recount', [InventoryCountController::class, 'recount'])->middleware('permission:inventory.counts.manage');
            Route::post('/inventory-counts/{inventoryCount}/submit', [InventoryCountController::class, 'submit'])->middleware('permission:inventory.counts.manage');
            Route::post('/inventory-counts/{inventoryCount}/approve', [InventoryCountController::class, 'approve'])->middleware('permission:inventory.counts.approve');
            Route::post('/inventory-counts/{inventoryCount}/cancel', [InventoryCountController::class, 'cancel'])->middleware('permission:inventory.counts.approve');

            Route::get('/products/lookup', [ProductController::class, 'lookup']);
            Route::get('/products/export', [ProductController::class, 'export']);
            Route::post('/products/{product}/image', [ProductController::class, 'uploadImage'])->middleware('permission:products.manage');
            Route::get('/shelves/{locationCode}/products', [ProductController::class, 'byShelf']);
            Route::post('/products/import', [ImportController::class, 'products'])->middleware('permission:import.execute');
            Route::get('/warehouses', [WarehouseController::class, 'index']);
            Route::post('/warehouses', [WarehouseOperationsController::class, 'storeWarehouse'])->middleware('permission:warehouses.manage');
            Route::put('/warehouses/{warehouse}', [WarehouseOperationsController::class, 'updateWarehouse'])->middleware('permission:warehouses.manage');
            Route::delete('/warehouses/{warehouse}', [WarehouseOperationsController::class, 'destroyWarehouse'])->middleware('permission:warehouses.manage');
            Route::get('/warehouse-locations', [WarehouseOperationsController::class, 'locations']);
            Route::post('/warehouse-locations', [WarehouseOperationsController::class, 'storeLocation'])->middleware('permission:warehouses.manage');
            Route::put('/warehouse-locations/{location}', [WarehouseOperationsController::class, 'updateLocation'])->middleware('permission:warehouses.manage');
            Route::delete('/warehouse-locations/{location}', [WarehouseOperationsController::class, 'destroyLocation'])->middleware('permission:warehouses.manage');
            Route::get('/stock-transfers', [WarehouseOperationsController::class, 'transfers'])->middleware('permission:transfers.view');
            Route::post('/stock-transfers', [WarehouseOperationsController::class, 'storeTransfer'])->middleware('permission:transfers.manage');
            Route::get('/stock-transfers/{stockTransfer}', [WarehouseOperationsController::class, 'showTransfer'])->middleware('permission:transfers.view');
            Route::put('/stock-transfers/{stockTransfer}', [WarehouseOperationsController::class, 'updateTransfer'])->middleware('permission:transfers.manage');
            Route::post('/stock-transfers/{stockTransfer}/dispatch', [WarehouseOperationsController::class, 'dispatchTransfer'])->middleware('permission:transfers.dispatch');
            Route::post('/stock-transfers/{stockTransfer}/receive', [WarehouseOperationsController::class, 'receiveTransfer'])->middleware('permission:transfers.receive');
            Route::post('/stock-transfers/{stockTransfer}/cancel', [WarehouseOperationsController::class, 'cancelTransfer'])->middleware('permission:transfers.manage');
            Route::get('/settings', [WarehouseController::class, 'settings']);
            Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchase_orders.view');
            Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchase_orders.view');
            Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase_orders.manage');
            Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchase_orders.manage');
            Route::patch('/purchase-orders/{purchaseOrder}/status', [PurchaseOrderController::class, 'status'])->middleware('permission:purchase_orders.manage');
            Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchase_orders.manage');
            Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->middleware('permission:purchase_orders.manage');
            Route::post('/purchase-orders/{purchaseOrder}/payments', [PurchaseOrderController::class, 'pay'])->middleware('permission:purchase_orders.payments');
            Route::post('/purchase-order-payments/{payment}/reverse', [PurchaseOrderController::class, 'reversePayment'])->middleware('permission:purchase_orders.payments');
            Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->middleware('permission:purchase_orders.receive');
            Route::get('/purchase-orders/{purchaseOrder}/statement', [PurchaseOrderController::class, 'statement'])->middleware('permission:purchase_orders.export');
            Route::get('/goods-receipts', [GoodsReceiptController::class, 'index'])->middleware('permission:purchase_orders.view');
            Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->middleware('permission:purchase_orders.view');
            Route::get('/goods-receipts/{goodsReceipt}/pdf', [GoodsReceiptController::class, 'pdf'])->middleware('permission:purchase_orders.export');
            Route::get('/landed-costs', [LandedCostController::class, 'index'])->middleware('permission:landed_costs.manage');
            Route::post('/landed-costs', [LandedCostController::class, 'store'])->middleware('permission:landed_costs.manage');
            Route::get('/landed-costs/{landedCost}', [LandedCostController::class, 'show'])->middleware('permission:landed_costs.manage');
            Route::post('/landed-costs/{landedCost}/post', [LandedCostController::class, 'post'])->middleware('permission:landed_costs.manage');
            Route::delete('/landed-costs/{landedCost}', [LandedCostController::class, 'destroy'])->middleware('permission:landed_costs.manage');
            Route::get('/replenishment', [ReplenishmentController::class, 'index'])->middleware('permission:replenishment.view');
            Route::post('/replenishment/draft-purchase-orders', [ReplenishmentController::class, 'createDraftPurchaseOrders'])->middleware('permission:replenishment.manage');
            Route::get('/product-suppliers', [ProductSupplierController::class, 'index'])->middleware('permission:supplier_catalogue.view');
            Route::post('/product-suppliers', [ProductSupplierController::class, 'store'])->middleware('permission:supplier_catalogue.manage');
            Route::get('/product-suppliers/{productSupplier}', [ProductSupplierController::class, 'show'])->middleware('permission:supplier_catalogue.view');
            Route::put('/product-suppliers/{productSupplier}', [ProductSupplierController::class, 'update'])->middleware('permission:supplier_catalogue.manage');
            Route::delete('/product-suppliers/{productSupplier}', [ProductSupplierController::class, 'destroy'])->middleware('permission:supplier_catalogue.manage');
            Route::get('/product-suppliers/{productSupplier}/price-history', [ProductSupplierController::class, 'priceHistory'])->middleware('permission:supplier_catalogue.view');
            Route::get('/suppliers/{supplier}/performance', [ProductSupplierController::class, 'supplierPerformance'])->middleware('permission:supplier_catalogue.view');

            Route::get('/inventory-returns', [InventoryReturnController::class, 'index'])->middleware('permission:returns.view');
            Route::post('/inventory-returns', [InventoryReturnController::class, 'store'])->middleware('permission:returns.manage');
            Route::get('/inventory-return-sources', [InventoryReturnController::class, 'sources'])->middleware('permission:returns.view');
            Route::get('/inventory-returns/{inventoryReturn}', [InventoryReturnController::class, 'show'])->middleware('permission:returns.view');
            Route::put('/inventory-returns/{inventoryReturn}', [InventoryReturnController::class, 'update'])->middleware('permission:returns.manage');
            Route::delete('/inventory-returns/{inventoryReturn}', [InventoryReturnController::class, 'destroy'])->middleware('permission:returns.manage');
            Route::post('/inventory-returns/{inventoryReturn}/submit', [InventoryReturnController::class, 'submit'])->middleware('permission:returns.manage');
            Route::post('/inventory-returns/{inventoryReturn}/approve', [InventoryReturnController::class, 'approve'])->middleware('permission:returns.approve');
            Route::post('/inventory-returns/{inventoryReturn}/complete', [InventoryReturnController::class, 'complete'])->middleware('permission:returns.approve');
            Route::post('/inventory-returns/{inventoryReturn}/cancel', [InventoryReturnController::class, 'cancel'])->middleware('permission:returns.manage');
            Route::get('/warehouse/layout', [WarehouseLayoutController::class, 'show']);
            Route::put('/warehouse/layout', [WarehouseLayoutController::class, 'updateWarehouse'])->middleware('permission:stock.manage');
            Route::get('/warehouse/sections', [WarehouseLayoutController::class, 'sectionOptions']);
            Route::get('/warehouse/sections/distribution', [WarehouseLayoutController::class, 'distribution']);
            Route::post('/warehouse/sections', [WarehouseLayoutController::class, 'storeSection'])->middleware('permission:stock.manage');
            Route::put('/warehouse/sections/{section}', [WarehouseLayoutController::class, 'updateSection'])->middleware('permission:stock.manage');
            Route::delete('/warehouse/sections/{section}', [WarehouseLayoutController::class, 'destroySection'])->middleware('permission:stock.manage');
            Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
            Route::post('/categories', [CategoryController::class, 'store'])->middleware('permission:categories.manage');
            Route::match(['put', 'patch'], '/categories/{category}', [CategoryController::class, 'update'])->middleware('permission:categories.manage');
            Route::apiResource('suppliers', SupplierController::class)->only(['index', 'show']);
            Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('permission:suppliers.manage');
            Route::match(['put', 'patch'], '/suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers.manage');
            Route::apiResource('products', ProductController::class)->only(['index', 'show']);
            Route::post('/products', [ProductController::class, 'store'])->middleware('permission:products.manage');
            Route::match(['put', 'patch'], '/products/{product}', [ProductController::class, 'update'])->middleware('permission:products.manage');

            Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:debts.view');
            Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:customers.manage');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.manage');
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.manage');
            Route::get('/customers/debts/summary', [CustomerController::class, 'summary'])->middleware('permission:debts.view');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:debts.view');
            Route::get('/customers/{customer}/statement', [CustomerController::class, 'statement'])->middleware('permission:debts.export');
            Route::post('/customers/{customer}/debts', [CustomerController::class, 'addDebt'])->middleware('permission:debts.create');
            Route::post('/customers/{customer}/payments', [CustomerController::class, 'payment'])->middleware('permission:debts.payments');
            Route::post('/debt-transactions/{transaction}/reverse', [CustomerController::class, 'reverse'])->middleware('permission:debts.adjust');
            Route::put('/debt-transactions/{transaction}', [CustomerController::class, 'correct'])->middleware('permission:debts.adjust');

            Route::get('/daily-sales/summary', [DailySaleController::class, 'summary'])->middleware('permission:daily_sales.manage');
            Route::get('/daily-sales/day-notes', [DailySaleController::class, 'dayNotes'])->middleware('permission:daily_sales.manage');
            Route::put('/daily-sales/day-notes', [DailySaleController::class, 'updateDayNotes'])->middleware('permission:daily_sales.manage');
            Route::post('/daily-sales/finalize-day', [DailySaleController::class, 'finalizeDay'])->middleware('permission:daily_sales.finalize');
            Route::get('/daily-sales/day-pdf', [DailySaleController::class, 'downloadDayPdf'])->middleware('permission:daily_sales.manage');
            Route::get('/daily-sales', [DailySaleController::class, 'index'])->middleware('permission:daily_sales.manage');
            Route::post('/daily-sales', [DailySaleController::class, 'store'])->middleware('permission:daily_sales.manage');
            Route::get('/daily-sales/{dailySale}', [DailySaleController::class, 'show'])->middleware('permission:daily_sales.manage');
            Route::put('/daily-sales/{dailySale}', [DailySaleController::class, 'update'])->middleware('permission:daily_sales.manage');
            Route::post('/daily-sales/{dailySale}/finalize', [DailySaleController::class, 'finalize'])->middleware('permission:daily_sales.finalize');
            Route::delete('/daily-sales/{dailySale}', [DailySaleController::class, 'destroy'])->middleware('permission:daily_sales.delete');
            Route::get('/daily-sales/{dailySale}/pdf', [DailySaleController::class, 'downloadPdf'])->middleware('permission:daily_sales.manage');

            Route::get('/invoice-profile', [InvoiceProfileController::class, 'show'])->middleware('permission:invoices.manage');
            Route::put('/invoice-profile', [InvoiceProfileController::class, 'update'])->middleware('permission:invoice_profile.manage');
            Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('permission:invoices.manage');
            Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('permission:invoices.manage');
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:invoices.manage');
            Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('permission:invoices.manage');
            Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('permission:invoices.manage');
            Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->middleware('permission:invoices.manage');
            Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('permission:invoices.manage');
            Route::post('/invoices/{invoice}/credit-note', [InvoiceController::class, 'creditNote'])->middleware('permission:invoices.manage');
            Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf'])->middleware('permission:invoices.manage');
            Route::get('/invoices/{invoice}/excel', [InvoiceController::class, 'downloadExcel'])->middleware('permission:invoices.manage');

            Route::get('/finance/overview', [FinanceController::class, 'overview'])->middleware('permission:finance.view');
            Route::get('/finance/vat-books', [FinanceController::class, 'vatBooks'])->middleware('permission:finance.view');
            Route::get('/finance/cash-flow', [FinanceController::class, 'cashFlow'])->middleware('permission:finance.view');
            Route::get('/finance/receivables-aging', [FinanceController::class, 'receivablesAging'])->middleware('permission:finance.view');
            Route::get('/finance/vat-books/export', [FinanceController::class, 'exportVatBooks'])->middleware('permission:finance.export');
            Route::get('/finance/expenses', [ExpenseController::class, 'index'])->middleware('permission:finance.view');
            Route::post('/finance/expenses', [ExpenseController::class, 'store'])->middleware('permission:expenses.manage');
            Route::get('/finance/expenses/{expense}', [ExpenseController::class, 'show'])->middleware('permission:finance.view');
            Route::put('/finance/expenses/{expense}', [ExpenseController::class, 'update'])->middleware('permission:expenses.manage');
            Route::delete('/finance/expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('permission:expenses.manage');
            Route::post('/finance/expenses/{expense}/post', [ExpenseController::class, 'post'])->middleware('permission:expenses.manage');
            Route::post('/finance/expenses/{expense}/reverse', [ExpenseController::class, 'reverse'])->middleware('permission:expenses.manage');
            Route::post('/finance/expenses/{expense}/payments', [ExpenseController::class, 'pay'])->middleware('permission:expenses.manage');
            Route::post('/finance/expense-payments/{expensePayment}/reverse', [ExpenseController::class, 'reversePayment'])->middleware('permission:expenses.manage');
            Route::post('/finance/expenses/{expense}/attachment', [ExpenseController::class, 'uploadAttachment'])->middleware('permission:expenses.manage');
            Route::get('/finance/expenses/{expense}/attachment', [ExpenseController::class, 'downloadAttachment'])->middleware('permission:finance.view');
            Route::delete('/finance/expenses/{expense}/attachment', [ExpenseController::class, 'deleteAttachment'])->middleware('permission:expenses.manage');

            Route::get('/supplier-invoices', [SupplierInvoiceController::class, 'index'])->middleware('permission:supplier_invoices.view');
            Route::post('/supplier-invoices', [SupplierInvoiceController::class, 'store'])->middleware('permission:supplier_invoices.manage');
            Route::get('/supplier-invoices/{supplierInvoice}', [SupplierInvoiceController::class, 'show'])->middleware('permission:supplier_invoices.view');
            Route::put('/supplier-invoices/{supplierInvoice}', [SupplierInvoiceController::class, 'update'])->middleware('permission:supplier_invoices.manage');
            Route::post('/supplier-invoices/{supplierInvoice}/recalculate', [SupplierInvoiceController::class, 'recalculate'])->middleware('permission:supplier_invoices.manage');
            Route::post('/supplier-invoices/{supplierInvoice}/allocate-payment', [SupplierInvoiceController::class, 'allocatePayment'])->middleware('permission:supplier_invoices.manage');
            Route::post('/supplier-invoices/{supplierInvoice}/approve', [SupplierInvoiceController::class, 'approve'])->middleware('permission:supplier_invoices.approve');

            Route::get('/finance/accounts', [FinancialAccountController::class, 'index'])->middleware('permission:financial_accounts.view');
            Route::post('/finance/accounts', [FinancialAccountController::class, 'store'])->middleware('permission:financial_accounts.manage');
            Route::put('/finance/accounts/{financialAccount}', [FinancialAccountController::class, 'update'])->middleware('permission:financial_accounts.manage');
            Route::get('/finance/accounts/{financialAccount}/transactions', [FinancialAccountController::class, 'transactions'])->middleware('permission:financial_accounts.view');
            Route::post('/finance/accounts/{financialAccount}/transactions', [FinancialAccountController::class, 'post'])->middleware('permission:financial_accounts.adjust');
            Route::post('/finance/account-transfers', [FinancialAccountController::class, 'transfer'])->middleware('permission:financial_accounts.manage');
            Route::post('/finance/account-transactions/{transaction}/reverse', [FinancialAccountController::class, 'reverse'])->middleware('permission:financial_accounts.adjust');
            Route::get('/finance/bank-statements', [BankReconciliationController::class, 'index'])->middleware('permission:bank_reconciliation.view');
            Route::post('/finance/bank-statements/import', [BankReconciliationController::class, 'import'])->middleware('permission:bank_reconciliation.manage');
            Route::get('/finance/bank-statements/{bankStatement}', [BankReconciliationController::class, 'show'])->middleware('permission:bank_reconciliation.view');
            Route::get('/finance/bank-statement-rows/{row}/suggestions', [BankReconciliationController::class, 'suggestions'])->middleware('permission:bank_reconciliation.view');
            Route::post('/finance/bank-statement-rows/{row}/reconcile', [BankReconciliationController::class, 'reconcile'])->middleware('permission:bank_reconciliation.confirm');
            Route::post('/finance/bank-statement-rows/{row}/ignore', [BankReconciliationController::class, 'ignore'])->middleware('permission:bank_reconciliation.confirm');
            Route::post('/finance/bank-statement-rows/{row}/unmatch', [BankReconciliationController::class, 'unmatch'])->middleware('permission:bank_reconciliation.confirm');

            Route::get('/payments', [PaymentController::class, 'index'])->middleware('permission:payments.process');
            Route::post('/payments', [PaymentController::class, 'store'])->middleware('permission:payments.process');
            Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('permission:payments.process');

            Route::middleware('role:admin')->group(function () {
                Route::get('/activity-logs', [ActivityLogController::class, 'index'])->middleware('permission:activity.view');
                Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.manage');
                Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.manage');
                Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.manage');
                Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.manage');
                Route::get('/cms', [CmsController::class, 'index'])->middleware('permission:cms.manage');
                Route::put('/cms/{cmsPage}', [CmsController::class, 'update'])->middleware('permission:cms.manage');
            });

            Route::middleware('role:admin,manager')->group(function () {
                Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.delete');
                Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->middleware('permission:categories.manage');
                Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:suppliers.manage');
            });
        });

        Route::middleware('role:superadmin')->prefix('superadmin')->group(function () {
            Route::get('/health', [HealthController::class, 'show']);
            Route::get('/dashboard', [SuperadminController::class, 'dashboard']);
            Route::get('/companies', [SuperadminController::class, 'companies']);
            Route::post('/companies', [SuperadminController::class, 'storeCompany']);
            Route::get('/users', [SuperadminController::class, 'users']);
            Route::post('/users', [SuperadminController::class, 'storeUser']);
            Route::put('/users/{user}', [SuperadminController::class, 'updateUser']);
            Route::post('/users/{user}/reset-password', [SuperadminController::class, 'resetPassword']);
            Route::delete('/users/{user}', [SuperadminController::class, 'destroyUser']);
        });
    });
});
