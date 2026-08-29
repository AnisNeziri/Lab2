<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shipment;
use App\Models\ShipmentContainer;
use App\Models\ShipmentDocument;
use App\Models\ShipmentItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShipmentLogisticsService
{
    public function details(Shipment $shipment): Shipment
    {
        return $shipment->load([
            'purchaseOrder.supplier', 'purchaseOrder.items.product:id,name,sku,unit', 'warehouse', 'supplier', 'histories',
            'containers.items.product:id,name,sku,unit', 'items.product:id,name,sku,unit',
            'items.purchaseOrderItem:id,purchase_order_id,product_id,description,unit,quantity,received_quantity',
            'documents' => fn ($query) => $query->with('uploader:id,name')->latest(),
        ]);
    }

    public function update(Shipment $shipment, array $data): Shipment
    {
        return DB::transaction(function () use ($shipment, $data) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $purchaseOrderWasProvided = array_key_exists('purchase_order_id', $data);
            $purchaseOrder = $purchaseOrderWasProvided
                ? (! empty($data['purchase_order_id']) ? PurchaseOrder::query()->findOrFail($data['purchase_order_id']) : null)
                : $shipment->purchaseOrder;
            $shipment->update([
                'purchase_order_id' => $purchaseOrderWasProvided ? $purchaseOrder?->id : $shipment->purchase_order_id,
                'warehouse_id' => array_key_exists('warehouse_id', $data)
                    ? $data['warehouse_id']
                    : ($purchaseOrderWasProvided ? $purchaseOrder?->warehouse_id : $shipment->warehouse_id),
                'supplier_id' => array_key_exists('supplier_id', $data)
                    ? $data['supplier_id']
                    : ($purchaseOrderWasProvided ? $purchaseOrder?->supplier_id : $shipment->supplier_id),
                'bill_of_lading' => $data['bill_of_lading'] ?? $shipment->bill_of_lading,
                'commercial_invoice_number' => $data['commercial_invoice_number'] ?? $shipment->commercial_invoice_number,
                'incoterm' => $data['incoterm'] ?? $shipment->incoterm,
                'transshipment_port' => $data['transshipment_port'] ?? $shipment->transshipment_port,
                'arrival_date' => array_key_exists('arrival_date', $data) ? $data['arrival_date'] : $shipment->arrival_date,
                'notes' => $data['notes'] ?? $shipment->notes,
                'tracking_provider' => $shipment->tracking_provider === 'aisstream' ? 'aisstream' : $shipment->tracking_provider,
                'tracking_mode' => $shipment->tracking_provider === 'aisstream' ? 'live_ais' : $shipment->tracking_mode,
            ]);
            if (array_key_exists('containers', $data)) {
                $this->syncContainers($shipment, $data['containers']);
            }
            if (array_key_exists('items', $data)) {
                $this->syncItems($shipment, $purchaseOrder, $data['items']);
            }

            return $this->details($shipment->fresh());
        });
    }

    public function addDocument(Shipment $shipment, string $type, UploadedFile $file): ShipmentDocument
    {
        $contents = $file->get();

        return ShipmentDocument::create([
            'company_id' => $shipment->company_id,
            'shipment_id' => $shipment->id,
            'document_type' => $type,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'file_data' => base64_encode($contents),
            'uploaded_by' => Auth::id(),
        ]);
    }

    public function deleteDocument(Shipment $shipment, ShipmentDocument $document): void
    {
        if ((int) $document->shipment_id !== (int) $shipment->id) {
            abort(404);
        }
        $document->delete();
    }

    private function syncContainers(Shipment $shipment, array $containers): void
    {
        $kept = [];
        foreach ($containers as $input) {
            $container = ! empty($input['id'])
                ? $shipment->containers()->whereKey($input['id'])->firstOrFail()
                : new ShipmentContainer(['company_id' => $shipment->company_id, 'shipment_id' => $shipment->id]);
            $container->fill([
                'container_number' => strtoupper(trim($input['container_number'])),
                'seal_number' => $input['seal_number'] ?? null,
                'container_type' => $input['container_type'] ?? null,
                'gross_weight_kg' => $input['gross_weight_kg'] ?? null,
                'volume_m3' => $input['volume_m3'] ?? null,
                'notes' => $input['notes'] ?? null,
            ])->save();
            $kept[] = $container->id;
        }
        $shipment->containers()->whereNotIn('id', $kept)->delete();
    }

    private function syncItems(Shipment $shipment, ?PurchaseOrder $order, array $items): void
    {
        $existing = $shipment->items()->get()->keyBy('id');
        $kept = [];
        foreach ($items as $input) {
            $line = ! empty($input['id']) ? $existing->get((int) $input['id']) : null;
            $poItem = ! empty($input['purchase_order_item_id']) ? PurchaseOrderItem::query()->findOrFail($input['purchase_order_item_id']) : null;
            if ($poItem && (! $order || (int) $poItem->purchase_order_id !== (int) $order->id)) {
                throw ValidationException::withMessages(['items' => ['Every linked purchase-order item must belong to the shipment purchase order.']]);
            }
            $product = $poItem?->product ?: (! empty($input['product_id']) ? Product::query()->findOrFail($input['product_id']) : null);
            $quantity = round((float) $input['quantity'], 3);
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['items' => ['Shipment quantities must be greater than zero.']]);
            }
            if ($poItem) {
                $allocated = (float) ShipmentItem::withoutGlobalScopes()
                    ->where('purchase_order_item_id', $poItem->id)
                    ->when($line, fn ($query) => $query->where('id', '!=', $line->id))
                    ->sum('quantity');
                if ($allocated + $quantity > (float) $poItem->quantity + 0.0005) {
                    throw ValidationException::withMessages(['items' => ["Shipment allocation exceeds the ordered quantity for {$poItem->description}."]]);
                }
            }
            if (! empty($input['shipment_container_id'])) {
                $belongs = $shipment->containers()->whereKey($input['shipment_container_id'])->exists();
                if (! $belongs) {
                    throw ValidationException::withMessages(['items' => ['The selected container does not belong to this shipment.']]);
                }
            }
            $values = [
                'company_id' => $shipment->company_id,
                'shipment_id' => $shipment->id,
                'shipment_container_id' => $input['shipment_container_id'] ?? null,
                'purchase_order_item_id' => $poItem?->id,
                'product_id' => $product?->id,
                'description' => $input['description'] ?? $poItem?->description ?? $product?->name ?? 'Shipment item',
                'unit' => $input['unit'] ?? $poItem?->unit ?? $product?->unit ?? 'pcs',
                'quantity' => $quantity,
                'base_quantity' => $input['base_quantity'] ?? ($poItem?->conversion_mode !== 'variable' ? $quantity * (float) ($poItem?->conversion_factor ?: 1) : null),
            ];
            if ($line) {
                $line->update($values);
            } else {
                $line = ShipmentItem::create($values);
            }
            $kept[] = $line->id;
        }
        $shipment->items()->whereNotIn('id', $kept)->delete();
    }
}
