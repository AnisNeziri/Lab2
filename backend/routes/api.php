<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CmsController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\UserController;
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
});

Route::middleware('auth.token')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    Route::middleware('password.changed')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/search', [SearchController::class, 'index']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

        Route::get('/stock-movements/export', [StockMovementController::class, 'export']);
        Route::get('/stock-movements', [StockMovementController::class, 'index']);
        Route::post('/stock-movements', [StockMovementController::class, 'store']);

        Route::get('/products/lookup', [ProductController::class, 'lookup']);
        Route::get('/products/export', [ProductController::class, 'export']);
        Route::post('/products/import', [ImportController::class, 'products']);
        Route::apiResource('categories', CategoryController::class)->except(['destroy']);
        Route::apiResource('suppliers', SupplierController::class)->except(['destroy']);
        Route::apiResource('products', ProductController::class)->except(['destroy']);

        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
        Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'downloadPdf']);

        Route::get('/payments', [PaymentController::class, 'index']);
        Route::post('/payments', [PaymentController::class, 'store']);

        Route::middleware('role:admin')->group(function () {
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::put('/users/{user}', [UserController::class, 'update']);
            Route::delete('/users/{user}', [UserController::class, 'destroy']);
            Route::get('/cms', [CmsController::class, 'index']);
            Route::put('/cms/{cmsPage}', [CmsController::class, 'update']);
        });

        Route::middleware('role:admin,manager')->group(function () {
            Route::delete('/products/{product}', [ProductController::class, 'destroy']);
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
            Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);
        });
    });
});
