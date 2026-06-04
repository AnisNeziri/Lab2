<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/status', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Inventory API is running',
    ]);
});

Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth.token')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/reports', [ReportController::class, 'index']);

    Route::get('/stock-movements/export', [StockMovementController::class, 'export']);
    Route::get('/stock-movements', [StockMovementController::class, 'index']);
    Route::post('/stock-movements', [StockMovementController::class, 'store']);

    Route::get('/products/lookup', [ProductController::class, 'lookup']);
    Route::get('/products/export', [ProductController::class, 'export']);
    Route::apiResource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('suppliers', SupplierController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('products', ProductController::class);

    // Invoices API
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'downloadPdf']);

    // Activity Logs API - Admin only
    Route::middleware('role:admin')->group(function () {
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    });

    // Sensitive operations - Admin or Manager only
    Route::middleware('role:admin,manager')->group(function () {
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);
    });
});
