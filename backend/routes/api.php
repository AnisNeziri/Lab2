<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StockMovementController;
use Illuminate\Support\Facades\Route;

Route::get('/status', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Inventory API is running',
    ]);
});

Route::get('/dashboard', [DashboardController::class, 'index']);

Route::get('/stock-movements', [StockMovementController::class, 'index']);
Route::post('/stock-movements', [StockMovementController::class, 'store']);

Route::get('/products/export', [ProductController::class, 'export']);
Route::apiResource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
Route::apiResource('products', ProductController::class);
