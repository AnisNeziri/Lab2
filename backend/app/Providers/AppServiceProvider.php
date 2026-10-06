<?php

namespace App\Providers;

use App\Contracts\IntelligenceProvider;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\StockMovementRepositoryInterface;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\CategoryRepository;
use App\Repositories\Eloquent\InvoiceRepository;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\StockMovementRepository;
use App\Repositories\Eloquent\SupplierRepository;
use App\Repositories\Eloquent\UserRepository;
use App\Services\DisabledIntelligenceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public $bindings = [\App\Contracts\DocumentStorageProvider::class => \App\Services\LocalDocumentStorage::class];
    public function register(): void
    {
        $this->app->bind(\App\Contracts\DemandForecastProvider::class, \App\Services\LocalDemandForecastProvider::class);
        $this->app->bind(ProductRepositoryInterface::class, ProductRepository::class);
        $this->app->bind(CategoryRepositoryInterface::class, CategoryRepository::class);
        $this->app->bind(StockMovementRepositoryInterface::class, StockMovementRepository::class);
        $this->app->bind(SupplierRepositoryInterface::class, SupplierRepository::class);
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(InvoiceRepositoryInterface::class, InvoiceRepository::class);
        $this->app->bind(IntelligenceProvider::class, \App\Services\LocalAssistantProvider::class);
    }

    public function boot(): void
    {
        foreach([\App\Models\Product::class,\App\Models\ProductSupplier::class,\App\Models\Supplier::class,\App\Models\Warehouse::class,\App\Models\WarehouseStock::class,\App\Models\StockMovement::class,\App\Models\PurchaseOrder::class,\App\Models\PurchaseRequest::class,\App\Models\StockTransfer::class,\App\Models\Shipment::class,\App\Models\AnalyticsPrediction::class] as $model)$model::observe(\App\Observers\SupplyOptimizationObserver::class);
        foreach ([\App\Models\Shipment::class, \App\Models\ShipmentMilestone::class, \App\Models\ShipmentContainer::class,
            \App\Models\ShipmentItem::class, \App\Models\GoodsReceipt::class, \App\Models\PurchaseOrder::class] as $model) {
            $model::observe(\App\Observers\ShipmentIntelligenceObserver::class);
        }
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
