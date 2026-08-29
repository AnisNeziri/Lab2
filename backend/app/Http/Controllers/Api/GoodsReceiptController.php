<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GoodsReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'purchase_order_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $receipts = GoodsReceipt::query()
            ->with(['purchaseOrder:id,po_number,supplier_id', 'purchaseOrder.supplier:id,name', 'warehouse:id,name,code', 'location:id,name,code,path', 'receiver:id,name'])
            ->withCount('items')
            ->when($validated['purchase_order_id'] ?? null, fn ($query, $id) => $query->where('purchase_order_id', $id))
            ->when($validated['warehouse_id'] ?? null, fn ($query, $id) => $query->where('warehouse_id', $id))
            ->when($validated['from'] ?? null, fn ($query, $date) => $query->whereDate('received_at', '>=', $date))
            ->when($validated['to'] ?? null, fn ($query, $date) => $query->whereDate('received_at', '<=', $date))
            ->latest('received_at')->paginate($validated['per_page'] ?? 20);

        return response()->json($receipts);
    }

    public function show(GoodsReceipt $goodsReceipt): JsonResponse
    {
        return response()->json($this->load($goodsReceipt));
    }

    public function pdf(GoodsReceipt $goodsReceipt): Response
    {
        $receipt = $this->load($goodsReceipt);
        $pdf = Pdf::loadView('goods-receipts.pdf', ['receipt' => $receipt])->setPaper('a4');

        return $pdf->download($receipt->receipt_number.'.pdf');
    }

    private function load(GoodsReceipt $receipt): GoodsReceipt
    {
        return $receipt->load([
            'company', 'purchaseOrder.supplier', 'warehouse', 'location', 'receiver:id,name',
            'items.product:id,name,sku,unit', 'items.purchaseOrderItem:id,description,unit,quantity,received_quantity',
        ]);
    }
}
