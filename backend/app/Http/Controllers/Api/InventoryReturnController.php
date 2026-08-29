<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryReturnRequest;
use App\Models\InventoryReturn;
use App\Models\DailySale;
use App\Models\GoodsReceipt;
use App\Services\InventoryReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryReturnController extends Controller
{
    public function __construct(private readonly InventoryReturnService $returns) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', 'in:customer,supplier'], 'status' => ['nullable', 'in:draft,submitted,approved,completed,cancelled'],
            'search' => ['nullable', 'string', 'max:255'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return response()->json($this->returns->list($filters));
    }

    public function sources(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['required', 'in:customer,supplier'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
        ]);

        if ($filters['type'] === 'customer') {
            $sales = DailySale::query()
                ->with(['customer:id,name', 'items.product:id,name,sku,unit,tracking_mode'])
                ->when($filters['customer_id'] ?? null, fn ($query, $id) => $query->where('customer_id', $id))
                ->latest('sale_date')->latest('id')->limit(100)->get();

            return response()->json(['sales' => $sales, 'receipts' => []]);
        }

        $receipts = GoodsReceipt::query()
            ->with([
                'purchaseOrder:id,po_number,supplier_id', 'purchaseOrder.supplier:id,name',
                'items.product:id,name,sku,unit,tracking_mode',
            ])
            ->when($filters['supplier_id'] ?? null, fn ($query, $id) => $query
                ->whereHas('purchaseOrder', fn ($order) => $order->where('supplier_id', $id)))
            ->latest('received_at')->latest('id')->limit(100)->get();

        return response()->json(['sales' => [], 'receipts' => $receipts]);
    }

    public function show(InventoryReturn $inventoryReturn): JsonResponse { return response()->json($this->returns->show($inventoryReturn)); }
    public function store(InventoryReturnRequest $request): JsonResponse { return response()->json($this->returns->create($request->validated()), 201); }
    public function update(InventoryReturnRequest $request, InventoryReturn $inventoryReturn): JsonResponse { return response()->json($this->returns->update($inventoryReturn, $request->validated())); }
    public function destroy(InventoryReturn $inventoryReturn): JsonResponse { $this->returns->delete($inventoryReturn); return response()->json(null, 204); }
    public function submit(InventoryReturn $inventoryReturn): JsonResponse { return response()->json($this->returns->submit($inventoryReturn)); }
    public function approve(InventoryReturn $inventoryReturn): JsonResponse { return response()->json($this->returns->approve($inventoryReturn)); }
    public function complete(InventoryReturn $inventoryReturn): JsonResponse { return response()->json($this->returns->complete($inventoryReturn)); }

    public function cancel(Request $request, InventoryReturn $inventoryReturn): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json($this->returns->cancel($inventoryReturn, $data['reason']));
    }
}
