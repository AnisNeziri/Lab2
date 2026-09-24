<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Rfq;
use App\Models\Shipment;
use App\Models\ShipmentContainer;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/** Permission-aware, company-scoped search over operational records. */
class SearchService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly PermissionService $permissions,
    ) {}

    public function search(string $term): array
    {
        $term = trim($term);
        $like = '%'.addcslashes($term, '%_\\').'%';
        $result = [];
        if ($this->can('documents.view')) {
            $result['documents']=app(DocumentService::class)->listing(['q'=>$term])->getCollection()->take(8)->map(fn($d)=>$this->item($d->id,$d->title,$d->reference.' · v'.$d->current_version,'/documents?document='.$d->id));
        }
        if ($this->can('fulfillment.view')) {
            $result['order_hub']=\App\Models\OrderIntake::with('channel:id,name')->where(fn($q)=>$q->where('external_id','like',$like)->orWhereHas('channel',fn($c)=>$c->where('external_reference','like',$like))->orWhereHas('order',fn($o)=>$o->where('order_number','like',$like)))->limit(8)->get()->map(fn($i)=>$this->item($i->id,$i->external_id?:'#'.$i->id,$i->channel?->name.' · '.$i->state,'/order-hub?intake='.$i->id));
            $result['sales_orders'] = \App\Models\SalesOrder::query()->with('customer:id,name')->where(fn($q)=>$q->where('order_number','like',$like)->orWhereHas('customer',fn($c)=>$c->where('name','like',$like)))->limit(8)->get()->map(fn($x)=>$this->item($x->id,$x->order_number,$x->customer?->name.' · '.$x->status,'/fulfillment?order='.$x->id));
            foreach (['pick_tasks'=>\App\Models\PickTask::class,'dispatches'=>\App\Models\OutboundDispatch::class,'returns'=>\App\Models\OutboundReturn::class] as $key=>$class) {
                $result[$key]=$class::query()->where('reference','like',$like)->limit(6)->get()->map(fn($x)=>$this->item($x->id,$x->reference,$x->status,'/fulfillment?order='.$x->sales_order_id));
            }
            $result['pick_waves']=\App\Models\PickWave::query()->where('reference','like',$like)->limit(6)->get()->map(fn($x)=>$this->item($x->id,$x->reference,$x->status,'/fulfillment?wave='.$x->id));
        }

        if ($this->can('products.manage', 'inventory.view')) {
            $result['products'] = $this->products->searchGlobal($term, 8)
                ->map(fn ($x) => $this->item($x->id, $x->name, $x->sku ?: $x->barcode, '/products?product='.$x->id));
            $result['stock_movements'] = StockMovement::query()->with('product:id,name,sku')
                ->where(fn ($q) => $q->where('reason', 'like', $like)->orWhere('type', 'like', $like)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $like)->orWhere('sku', 'like', $like)))
                ->latest()->limit(5)->get()
                ->map(fn ($x) => $this->item($x->id, $x->product?->name ?: 'Stock movement', strtoupper($x->type).' '.$x->quantity, '/stock?movement='.$x->id));
        }

        if ($this->can('suppliers.manage', 'supplier_catalogue.view', 'procurement.view')) {
            $result['suppliers'] = Supplier::query()
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like))
                ->limit(6)->get()->map(fn ($x) => $this->item($x->id, $x->name, $x->email ?: $x->phone, '/suppliers?supplier='.$x->id));
        }

        if ($this->can('customers.manage', 'debts.view', 'invoices.manage')) {
            $result['customers'] = Customer::query()
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('business_registration_number', 'like', $like)
                    ->orWhere('fiscal_number', 'like', $like)->orWhere('email', 'like', $like))
                ->limit(6)->get()->map(fn ($x) => $this->item($x->id, $x->name, $x->business_registration_number ?: $x->email, '/customer-debts?customer='.$x->id));
        }

        if ($this->can('invoices.manage')) {
            $result['invoices'] = Invoice::query()
                ->where(fn ($q) => $q->where('invoice_number', 'like', $like)->orWhere('customer_name', 'like', $like))
                ->limit(6)->get()->map(fn ($x) => $this->item($x->id, $x->invoice_number, $x->customer_name.' · '.$x->status, '/invoices?invoice='.$x->id));
        }

        if ($this->can('purchase_orders.view')) {
            $result['purchase_orders'] = PurchaseOrder::query()->with('supplier:id,name')
                ->where(fn ($q) => $q->where('po_number', 'like', $like)->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $like)))
                ->limit(6)->get()->map(fn ($x) => $this->item($x->id, $x->po_number, ($x->supplier?->name ?: 'Supplier').' · '.$x->status, '/purchase-orders?po='.$x->id));
        }

        if ($this->can('procurement.view')) {
            $result['purchase_requests'] = PurchaseRequest::query()
                ->where(fn ($q) => $q->where('request_number', 'like', $like)->orWhere('notes', 'like', $like))
                ->limit(6)->get()->map(fn ($x) => $this->item($x->id, $x->request_number, $x->status, '/procurement?request='.$x->id));
            $result['rfqs'] = Rfq::query()
                ->where(fn ($q) => $q->where('rfq_number', 'like', $like)->orWhere('notes', 'like', $like))
                ->limit(6)->get()->map(fn ($x) => $this->item($x->id, $x->rfq_number, $x->status, '/procurement?rfq='.$x->id));
        }

        if ($this->can('shipments.view')) {
            $result['shipments'] = Shipment::query()->active()
                ->where(fn ($q) => $q->where('tracking_number', 'like', $like)->orWhere('tracking_reference', 'like', $like)
                    ->orWhere('bill_of_lading', 'like', $like)->orWhere('vessel_name', 'like', $like)->orWhere('mmsi', 'like', $like)->orWhere('imo', 'like', $like))
                ->limit(6)->get()->map(fn ($x) => $this->item($x->id, $x->vessel_name ?: ($x->tracking_number ?: $x->tracking_reference), $x->status, '/shipments/my-shipments?shipment='.$x->id));
            $result['containers'] = ShipmentContainer::query()->with('shipment:id,vessel_name')
                ->where(fn ($q) => $q->where('container_number', 'like', $like)->orWhere('booking_reference', 'like', $like)->orWhere('bill_of_lading', 'like', $like))
                ->limit(6)->get()->map(fn ($x) => $this->item($x->id, $x->container_number, $x->shipment?->vessel_name ?: $x->status, '/control-tower/'.$x->shipment_id));
        }

        if ($this->can('inventory.view', 'transfers.view')) {
            $result['warehouses'] = Warehouse::query()->where('is_active', true)
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('address', 'like', $like))
                ->limit(6)->get()->map(fn ($x) => $this->item($x->id, $x->name, $x->code, '/warehouse-operations?warehouse='.$x->id));
            $result['bins'] = WarehouseLocation::query()->with('warehouse:id,name')->where('is_active', true)
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('path', 'like', $like))
                ->limit(6)->get()->map(fn ($x) => $this->item($x->id, $x->name ?: $x->code, ($x->warehouse?->name ?: 'Warehouse').' · '.$x->path, '/warehouse-operations?warehouse='.$x->warehouse_id.'&location='.$x->id));
        }

        if ($this->can('accounting.journal.view')) {
            $result['journals'] = JournalEntry::query()
                ->where(fn ($q) => $q->where('journal_number', 'like', $like)->orWhere('reference_number', 'like', $like)->orWhere('description', 'like', $like))
                ->latest('posting_date')->limit(6)->get()->map(fn ($x) => $this->item($x->id, $x->journal_number, $x->description ?: $x->status, '/accounting?tab=journal&journal='.$x->id));
        }

        return collect($result)->map(fn (Collection $items) => $items->values()->all())
            ->filter(fn (array $items) => $items !== [])->all();
    }

    private function can(string ...$permissions): bool
    {
        $role = Auth::user()?->role;

        return $role !== null && collect($permissions)
            ->contains(fn (string $permission) => $this->permissions->roleHasPermission($role, $permission));
    }

    private function item(int $id, ?string $title, mixed $subtitle, string $url): array
    {
        return ['id' => $id, 'title' => $title ?: 'Untitled', 'subtitle' => (string) ($subtitle ?? ''), 'url' => $url];
    }
}
