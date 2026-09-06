<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shipment;
use App\Models\ShipmentContainer;
use App\Models\ShipmentDocument;
use App\Models\ShipmentItem;
use App\Models\ActivityLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShipmentLogisticsService
{
    public function __construct(private readonly BusinessEventService $events) {}

    public function details(Shipment $shipment): Shipment
    {
        return $shipment->load([
            'purchaseOrder.supplier', 'purchaseOrder.items.product:id,name,sku,unit',
            'purchaseOrders.supplier', 'purchaseOrders.items.product:id,name,sku,unit', 'warehouse', 'supplier', 'histories',
            'containers.purchaseOrders', 'containers.items.product:id,name,sku,unit,weight_kg,volume_m3', 'items.product:id,name,sku,unit,weight_kg,volume_m3',
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
            $purchaseOrderIds = array_key_exists('purchase_order_ids', $data)
                ? collect($data['purchase_order_ids'])->map(fn ($id) => (int) $id)->filter()->unique()->values()
                : $shipment->purchaseOrders()->pluck('purchase_orders.id');
            if ($purchaseOrder) {
                $purchaseOrderIds->push((int) $purchaseOrder->id);
            }
            $purchaseOrderIds = $purchaseOrderIds->unique()->values();
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
            $shipment->purchaseOrders()->syncWithPivotValues($purchaseOrderIds->all(), ['company_id' => $shipment->company_id]);
            $containerMap = [];
            if (array_key_exists('containers', $data)) {
                $containerMap = $this->syncContainers($shipment, $data['containers']);
            }
            if (array_key_exists('items', $data)) {
                $this->syncItems($shipment, $purchaseOrderIds, $data['items'], $containerMap);
            }
            $this->syncContainerOrders($shipment);

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

    private function syncContainers(Shipment $shipment, array $containers): array
    {
        $kept = [];
        $map = [];
        foreach ($containers as $input) {
            $container = ! empty($input['id'])
                ? $shipment->containers()->whereKey($input['id'])->firstOrFail()
                : new ShipmentContainer(['company_id' => $shipment->company_id, 'shipment_id' => $shipment->id]);
            $before = $container->exists ? $container->only($this->containerAuditFields()) : null;
            $container->fill([
                'container_number' => strtoupper(trim($input['container_number'])),
                'seal_number' => $input['seal_number'] ?? null,
                'container_type' => $input['container_type'] ?? null,
                'booking_reference' => $input['booking_reference'] ?? null,
                'bill_of_lading' => $input['bill_of_lading'] ?? null,
                'forwarder' => $input['forwarder'] ?? null,
                'vessel_name' => $input['vessel_name'] ?? null,
                'voyage' => $input['voyage'] ?? null,
                'origin_port' => $input['origin_port'] ?? null,
                'destination_port' => $input['destination_port'] ?? null,
                'etd' => $input['etd'] ?? null,
                'eta' => $input['eta'] ?? null,
                'actual_departure' => $input['actual_departure'] ?? null,
                'actual_arrival' => $input['actual_arrival'] ?? null,
                'status' => $input['status'] ?? 'planned',
                'capacity_cbm' => $input['capacity_cbm'] ?? null,
                'capacity_weight_kg' => $input['capacity_weight_kg'] ?? null,
                'gross_weight_kg' => $input['gross_weight_kg'] ?? null,
                'volume_m3' => $input['volume_m3'] ?? null,
                'notes' => $input['notes'] ?? null,
            ])->save();
            $after = $container->only($this->containerAuditFields());
            if ($before !== $after) {
                ActivityLog::create([
                    'company_id' => $shipment->company_id, 'user_id' => Auth::id(),
                    'action' => $before ? 'shipment.container.updated' : 'shipment.container.created',
                    'entity' => 'ShipmentContainer', 'entity_id' => $container->id,
                    'description' => ($before ? 'Shipment container updated: ' : 'Shipment container created: ').$container->container_number.'.',
                    'old_value' => $before, 'new_value' => $after,
                    'ip_address' => request()?->ip(),
                ]);
            }
            foreach ([
                'container.loaded' => $container->status === 'loaded' ? 'state' : null,
                'container.departed' => $container->actual_departure?->timestamp,
                'container.arrived' => $container->actual_arrival?->timestamp,
                'container.customs_cleared' => $container->status === 'customs_cleared' ? 'state' : null,
            ] as $eventType => $eventVersion) {
                if ($eventVersion) {
                    $this->events->record($eventType, $container, $container->container_number, [
                        'shipment_id' => $shipment->id, 'status' => $container->status,
                    ], "container:{$container->id}:{$eventType}:{$eventVersion}");
                }
            }
            $kept[] = $container->id;
            $map[(string) ($input['client_key'] ?? $container->id)] = $container->id;
        }
        $shipment->containers()->whereNotIn('id', $kept)->get()->each(function (ShipmentContainer $container) use ($shipment): void {
            ActivityLog::create([
                'company_id' => $shipment->company_id, 'user_id' => Auth::id(),
                'action' => 'shipment.container.deleted', 'entity' => 'ShipmentContainer',
                'entity_id' => $container->id, 'description' => 'Shipment container deleted: '.$container->container_number.'.',
                'old_value' => $container->only($this->containerAuditFields()), 'new_value' => null,
                'ip_address' => request()?->ip(),
            ]);
            $container->delete();
        });

        return $map;
    }

    private function containerAuditFields(): array
    {
        return [
            'container_number', 'seal_number', 'container_type', 'booking_reference',
            'bill_of_lading', 'forwarder', 'vessel_name', 'voyage', 'origin_port',
            'destination_port', 'etd', 'eta', 'actual_departure', 'actual_arrival',
            'status', 'capacity_cbm', 'capacity_weight_kg', 'gross_weight_kg', 'volume_m3',
        ];
    }

    private function syncItems(Shipment $shipment, $purchaseOrderIds, array $items, array $containerMap = []): void
    {
        $existing = $shipment->items()->get()->keyBy('id');
        $kept = [];
        foreach ($items as $input) {
            $line = ! empty($input['id']) ? $existing->get((int) $input['id']) : null;
            $poItem = ! empty($input['purchase_order_item_id']) ? PurchaseOrderItem::query()->findOrFail($input['purchase_order_item_id']) : null;
            if ($poItem && ! $purchaseOrderIds->contains((int) $poItem->purchase_order_id)) {
                throw ValidationException::withMessages(['items' => ['Every linked purchase-order item must belong to a Purchase Order linked to this shipment.']]);
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
            $containerId = $input['shipment_container_id'] ?? null;
            if (! $containerId && ! empty($input['shipment_container_key'])) {
                $containerId = $containerMap[(string) $input['shipment_container_key']] ?? null;
            }
            if ($containerId) {
                $belongs = $shipment->containers()->whereKey($containerId)->exists();
                if (! $belongs) {
                    throw ValidationException::withMessages(['items' => ['The selected container does not belong to this shipment.']]);
                }
            }
            $values = [
                'company_id' => $shipment->company_id,
                'shipment_id' => $shipment->id,
                'shipment_container_id' => $containerId,
                'purchase_order_item_id' => $poItem?->id,
                'product_id' => $product?->id,
                'description' => $input['description'] ?? $poItem?->description ?? $product?->name ?? 'Shipment item',
                'unit' => $input['unit'] ?? $poItem?->unit ?? $product?->unit ?? 'pcs',
                'quantity' => $quantity,
                'planned_quantity' => $input['planned_quantity'] ?? $quantity,
                'loaded_quantity' => $input['loaded_quantity'] ?? null,
                'base_quantity' => $input['base_quantity'] ?? ($poItem?->conversion_mode !== 'variable' ? $quantity * (float) ($poItem?->conversion_factor ?: 1) : null),
                'unit_cbm' => $input['unit_cbm'] ?? $product?->volume_m3,
                'unit_weight_kg' => $input['unit_weight_kg'] ?? $product?->weight_kg,
            ];
            if ($values['loaded_quantity'] !== null && (float) $values['loaded_quantity'] > (float) $values['planned_quantity'] + 0.0005) {
                throw ValidationException::withMessages(['items' => ['Loaded quantity cannot exceed planned quantity.']]);
            }
            if ($line) {
                $line->update($values);
            } else {
                $line = ShipmentItem::create($values);
            }
            $kept[] = $line->id;
        }
        $shipment->items()->whereNotIn('id', $kept)->delete();
    }

    private function syncContainerOrders(Shipment $shipment): void
    {
        $shipment->containers()->with('items.purchaseOrderItem')->get()->each(function (ShipmentContainer $container): void {
            $ids = $container->items->pluck('purchaseOrderItem.purchase_order_id')->filter()->unique()->values()->all();
            $container->purchaseOrders()->syncWithPivotValues($ids, ['company_id' => $container->company_id]);
        });
    }
}
