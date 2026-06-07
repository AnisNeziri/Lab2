<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;

class WarehouseController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Warehouse::withCount('stock')->orderBy('name')->get());
    }

    public function settings(): JsonResponse
    {
        return response()->json(Setting::orderBy('key')->get(['key', 'value']));
    }

    public function purchaseOrders(): JsonResponse
    {
        return response()->json(
            PurchaseOrder::with(['supplier:id,name'])->latest()->limit(50)->get()
        );
    }
}
