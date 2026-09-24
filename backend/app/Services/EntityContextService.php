<?php

namespace App\Services;

use App\Models\BusinessEvent;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EntityContextService
{
    private const TYPES = [
        'product' => [Product::class, ['products.manage', 'inventory.view']],
        'supplier' => [Supplier::class, ['suppliers.manage', 'supplier_catalogue.view', 'procurement.view']],
        'customer' => [Customer::class, ['customers.manage', 'debts.view', 'invoices.manage']],
        'purchase-order' => [PurchaseOrder::class, ['purchase_orders.view']],
        'warehouse' => [\App\Models\Warehouse::class, ['inventory.view']],
    ];

    public function __construct(private readonly PermissionService $permissions) {}

    public function get(string $type, int $id): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        [$modelClass, $requiredPermissions] = self::TYPES[$type];
        $role = (string) Auth::user()->role;
        abort_unless(collect($requiredPermissions)->contains(fn (string $permission) => $this->permissions->roleHasPermission($role, $permission)), 403);

        /** @var Model $entity */
        $entity = $modelClass::query()->findOrFail($id);
        $events = BusinessEvent::query()->with('actor:id,name')
            ->where('entity_type', class_basename($entity))->where('entity_id', $entity->getKey())
            ->latest('occurred_at')->limit(12)->get()->map(fn (BusinessEvent $event) => [
                'key' => 'event-'.$event->id,
                'title' => str($event->event_type)->replace(['.', '_'], ' ')->headline()->toString(),
                'detail' => $event->reference ?: ($event->actor?->name ?: 'System'),
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'url' => null,
            ]);

        return [
            'entity' => ['type' => $type, 'id' => $entity->getKey(), 'label' => $this->label($entity)],
            'activity' => $events->concat($this->domainActivity($type, $entity))->concat($this->outboundActivity($type,$entity))->sortByDesc('occurred_at')->take(12)->values()->all(),
            'outbound' => in_array($type,['customer','product','warehouse'])&&$this->permissions->roleHasPermission($role,'fulfillment.view')?app(OutboundReportingService::class)->context($type,$id):null,
        ];
    }

    private function outboundActivity(string $type,Model $entity):Collection
    {
        if(!in_array($type,['customer','product'])||!$this->permissions->roleHasPermission(Auth::user()->role,'fulfillment.view'))return collect();
        return \App\Models\SalesOrder::query()->when($type==='customer',fn($q)=>$q->where('customer_id',$entity->id),fn($q)=>$q->whereHas('items',fn($i)=>$i->where('product_id',$entity->id)))->latest()->limit(8)->get()->map(fn($o)=>['key'=>'outbound-'.$o->id,'title'=>$o->order_number,'detail'=>$o->status,'occurred_at'=>$o->updated_at->toIso8601String(),'url'=>'/fulfillment?order='.$o->id]);
    }
    private function domainActivity(string $type, Model $entity): Collection
    {
        return match ($type) {
            'product' => DB::table('stock_movements')->where('company_id', Auth::user()->company_id)->where('product_id', $entity->getKey())
                ->latest('occurred_at')->limit(8)->get()->map(fn ($row) => [
                    'key' => 'movement-'.$row->id,
                    'title' => 'Stock '.($row->type === 'in' ? 'received' : 'issued'),
                    'detail' => trim(($row->movement_code ?: $row->reason ?: 'Movement').' · '.(string) $row->quantity),
                    'occurred_at' => $row->occurred_at ?: $row->created_at,
                    'url' => '/stock?movement='.$row->id,
                ]),
            'supplier' => DB::table('purchase_orders')->where('company_id', Auth::user()->company_id)->where('supplier_id', $entity->getKey())
                ->latest('created_at')->limit(8)->get()->map(fn ($row) => [
                    'key' => 'po-'.$row->id,
                    'title' => 'Purchase order '.$row->po_number,
                    'detail' => str((string) $row->status)->replace('_', ' ')->headline()->toString(),
                    'occurred_at' => $row->created_at,
                    'url' => '/purchase-orders?po='.$row->id,
                ]),
            'customer' => DB::table('customer_debt_transactions')->where('company_id', Auth::user()->company_id)->where('customer_id', $entity->getKey())
                ->latest('transaction_date')->limit(8)->get()->map(fn ($row) => [
                    'key' => 'customer-transaction-'.$row->id,
                    'title' => str((string) $row->type)->replace('_', ' ')->headline()->toString(),
                    'detail' => '€'.number_format((float) $row->amount, 2).($row->note ? ' · '.$row->note : ''),
                    'occurred_at' => $row->transaction_date,
                    'url' => '/customer-debts?customer='.$entity->getKey(),
                ]),
            'purchase-order' => DB::table('goods_receipts')->where('company_id', Auth::user()->company_id)->where('purchase_order_id', $entity->getKey())
                ->latest('received_at')->limit(8)->get()->map(fn ($row) => [
                    'key' => 'receipt-'.$row->id,
                    'title' => 'Goods receipt '.$row->receipt_number,
                    'detail' => str((string) $row->status)->replace('_', ' ')->headline()->toString(),
                    'occurred_at' => $row->received_at ?: $row->created_at,
                    'url' => '/warehouse-operations?receipt='.$row->id,
                ]),
            default => collect(),
        };
    }

    private function label(Model $entity): string
    {
        return (string) ($entity->name ?? $entity->po_number ?? $entity->getKey());
    }
}
