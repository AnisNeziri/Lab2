<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\StockTransfer;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Repositories\Contracts\ProductRepositoryInterface;

class SearchService
{
    public function __construct(
        private ProductRepositoryInterface $products
    ) {}

    public function search(string $term): array
    {
        $like = "%{$term}%";

        $products = $this->products->searchGlobal($term, 10);

        $categories = Category::where('name', 'like', $like)
            ->limit(5)
            ->get(['id', 'name']);

        $suppliers = Supplier::where(fn ($query) => $query->where('name', 'like', $like)
            ->orWhere('email', 'like', $like))
            ->limit(5)
            ->get(['id', 'name', 'email']);

        $invoices = Invoice::where(fn ($query) => $query->where('invoice_number', 'like', $like)
            ->orWhere('customer_name', 'like', $like))
            ->limit(5)
            ->get(['id', 'invoice_number', 'customer_name', 'status', 'total_amount']);

        $stockMovements = StockMovement::with('product:id,name,sku')
            ->where(function ($query) use ($like) {
                $query->where('reason', 'like', $like)
                    ->orWhere('type', 'like', $like)
                    ->orWhereHas('product', function ($productQuery) use ($like) {
                        $productQuery->where('name', 'like', $like)->orWhere('sku', 'like', $like);
                    });
            })
            ->latest()
            ->limit(5)
            ->get(['id', 'product_id', 'type', 'quantity', 'reason', 'created_at']);

        $customers = Customer::query()->where(function ($query) use ($like) {
            $query->where('name', 'like', $like)->orWhere('business_registration_number', 'like', $like)
                ->orWhere('fiscal_number', 'like', $like)->orWhere('email', 'like', $like);
        })->limit(5)->get(['id', 'name', 'business_registration_number', 'email']);

        $purchaseOrders = PurchaseOrder::with('supplier:id,name')->where(function ($query) use ($like) {
            $query->where('po_number', 'like', $like)->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', $like));
        })->limit(5)->get(['id', 'supplier_id', 'po_number', 'status', 'total_amount', 'currency']);

        $shipments = Shipment::query()->where(function ($query) use ($like) {
            $query->where('tracking_number', 'like', $like)->orWhere('tracking_reference', 'like', $like)
                ->orWhere('bill_of_lading', 'like', $like)->orWhere('vessel_name', 'like', $like)
                ->orWhere('destination_port', 'like', $like);
        })->limit(5)->get(['id', 'tracking_number', 'tracking_reference', 'bill_of_lading', 'status', 'destination_port']);

        $transfers = StockTransfer::with(['sourceWarehouse:id,name', 'destinationWarehouse:id,name'])
            ->where('transfer_number', 'like', $like)->limit(5)
            ->get(['id', 'transfer_number', 'source_warehouse_id', 'destination_warehouse_id', 'status']);

        $goodsReceipts = GoodsReceipt::with('purchaseOrder:id,po_number')
            ->where(fn ($query) => $query->where('receipt_number', 'like', $like)->orWhere('supplier_document_number', 'like', $like))
            ->limit(5)->get(['id', 'purchase_order_id', 'receipt_number', 'supplier_document_number', 'received_at']);

        return [
            'products' => $products,
            'categories' => $categories,
            'suppliers' => $suppliers,
            'invoices' => $invoices,
            'stock_movements' => $stockMovements,
            'customers' => $customers,
            'purchase_orders' => $purchaseOrders,
            'shipments' => $shipments,
            'stock_transfers' => $transfers,
            'goods_receipts' => $goodsReceipts,
        ];
    }
}
