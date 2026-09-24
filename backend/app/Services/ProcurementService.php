<?php
namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ProcurementAward;
use App\Models\PurchaseRequest;
use App\Models\Rfq;
use App\Models\RfqSupplier;
use App\Models\SupplierQuote;
use App\Models\SupplierQuoteItem;
use App\Support\CompanyCurrency;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcurementService
{
    public function __construct(private readonly ApprovalService $approvals, private readonly PurchaseOrderService $orders, private readonly SupplierPerformanceService $supplierPerformance) {}
    public function requests() { return PurchaseRequest::query()->with(['items.product','approval','rfqs'])->latest('id')->paginate(50); }
    public function findRequest(PurchaseRequest $request): PurchaseRequest { return $request->load(['items.product','approval.decisions','rfqs.suppliers','rfqs.quotes.items']); }
    public function createRequest(array $data): PurchaseRequest { return DB::transaction(function() use($data) { $company=(int)Auth::user()->company_id; $currency=strtoupper($data['currency'] ?? CompanyCurrency::current()); $total=Money::add(...array_map(fn($i)=>Money::multiply($i['estimated_unit_price'] ?? 0,$i['quantity']),$data['items'])); $request=PurchaseRequest::create(['company_id'=>$company,'request_number'=>$this->number('PR'),'status'=>'draft','requested_by'=>Auth::id(),'requested_at'=>$data['requested_at'] ?? now()->toDateString(),'required_by'=>$data['required_by'] ?? null,'notes'=>$data['notes'] ?? null,'estimated_total'=>$total,'currency'=>$currency]); foreach($data['items'] as $item) $request->items()->create($item); $this->audit($request,'procurement.request.created','Purchase request created.'); return $this->findRequest($request); }); }
    public function updateRequest(PurchaseRequest $request,array $data): PurchaseRequest { if(!in_array($request->status,['draft','rejected'],true)) throw ValidationException::withMessages(['status'=>['Only draft or rejected purchase requests can be revised.']]); return DB::transaction(function()use($request,$data){ $total=Money::add(...array_map(fn($i)=>Money::multiply($i['estimated_unit_price'] ?? 0,$i['quantity']),$data['items'])); $request->update(['requested_at'=>$data['requested_at'] ?? $request->requested_at,'required_by'=>$data['required_by']??null,'notes'=>$data['notes']??null,'currency'=>strtoupper($data['currency']),'estimated_total'=>$total,'status'=>'draft','approval_request_id'=>null]); $request->items()->delete(); foreach($data['items'] as $item)$request->items()->create($item); $this->audit($request,'procurement.request.revised','Purchase request revised.'); return $this->findRequest($request);}); }
    public function submit(PurchaseRequest $request): PurchaseRequest { if(!in_array($request->status,['draft','rejected'],true)) throw ValidationException::withMessages(['status'=>['Only a draft or rejected request can be submitted.']]); return DB::transaction(function()use($request){$request->update(['status'=>'submitted']); $approval=$this->approvals->requestIfRequired('purchase_request',$request->id,'purchase_request',(string)$request->estimated_total,$request->currency,['request_number'=>$request->request_number]); $request->update(['approval_request_id'=>$approval?->id,'status'=>$approval?'submitted':'approved']); $this->audit($request,'procurement.request.submitted','Purchase request submitted.');return $this->findRequest($request);}); }
    public function createRfq(PurchaseRequest $request,array $data): Rfq { if($request->status!=='approved') throw ValidationException::withMessages(['status'=>['Only an approved purchase request can move to sourcing.']]); return DB::transaction(function()use($request,$data){$rfq=Rfq::create(['company_id'=>$request->company_id,'purchase_request_id'=>$request->id,'rfq_number'=>$this->number('RFQ'),'status'=>'draft','response_due_at'=>$data['response_due_at']??null,'notes'=>$data['notes']??null,'created_by'=>Auth::id()]);foreach(array_unique($data['supplier_ids']) as $id) RfqSupplier::create(['rfq_id'=>$rfq->id,'supplier_id'=>$id,'status'=>'invited']);$request->update(['status'=>'sourcing']);$this->audit($rfq,'procurement.rfq.created','RFQ created from approved purchase request.');return $rfq->load('suppliers');}); }
    public function issueRfq(Rfq $rfq): Rfq { if($rfq->status!=='draft') throw ValidationException::withMessages(['status'=>['Only a draft RFQ can be issued.']]);$rfq->update(['status'=>'issued','issued_at'=>now()]);$rfq->suppliers()->update(['status'=>'issued','sent_at'=>now()]);$this->audit($rfq,'procurement.rfq.issued','RFQ issued to selected suppliers.');return $rfq->fresh('suppliers'); }
    public function createQuote(Rfq $rfq, array $data): SupplierQuote
    {
        return DB::transaction(function () use ($rfq, $data) {
            $rfq = Rfq::query()->lockForUpdate()->findOrFail($rfq->id);
            if (!in_array($rfq->status, ['draft','issued','responses_received','evaluated','partially_awarded'], true)) {
                throw ValidationException::withMessages(['status'=>['This RFQ is closed.']]);
            }
            if (!$rfq->suppliers()->where('supplier_id',$data['supplier_id'])->exists()) {
                throw ValidationException::withMessages(['supplier_id'=>['Supplier is not invited to this RFQ.']]);
            }
            $ids = array_column($data['items'], 'purchase_request_item_id');
            if (count($ids) !== count(array_unique($ids)) || $rfq->purchaseRequest->items()->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['items'=>['Every quote line must refer to a different item in this RFQ request.']]);
            }
            $revision=1+(int)SupplierQuote::query()->where('rfq_id',$rfq->id)->where('supplier_id',$data['supplier_id'])->max('revision');
            $quote=SupplierQuote::create([...$data,'company_id'=>$rfq->company_id,'rfq_id'=>$rfq->id,'revision'=>$revision,'status'=>'received','created_by'=>Auth::id()]);
            foreach($data['items'] as $item) $quote->items()->create($item);
            if ($rfq->status !== 'partially_awarded') $rfq->update(['status'=>'responses_received']);
            $this->audit($quote,'procurement.quote.received','Supplier quote recorded as a new revision.');
            return $quote->load('items');
        });
    }
    public function comparison(Rfq $rfq): Rfq
    {
        $rfq->load(['purchaseRequest.items.product','suppliers','quotes.supplier','quotes.items.requestItem']);
        $rfq->setAttribute('awards', ProcurementAward::query()->where('rfq_id', $rfq->id)->get());
        foreach ($rfq->quotes as $quote) {
            foreach ($quote->items as $item) {
                $subtotal = Money::multiply($item->unit_price, $item->offered_quantity);
                $item->setAttribute('line_subtotal', $subtotal);
                $item->setAttribute('base_subtotal', Money::multiply($subtotal, $quote->exchange_rate));
            }
        }
        $scorecards = [];
        foreach ($rfq->quotes as $quote) {
            if (! $quote->supplier) continue;
            $scorecards[$quote->supplier_id] ??= $this->supplierPerformance->scorecard($quote->supplier);
            $quote->setAttribute('supplier_performance', $scorecards[$quote->supplier_id]);
        }
        return $rfq;
    }
    public function award(Rfq $rfq, array $quoteItemIds): Rfq
    {
        return DB::transaction(function() use($rfq, $quoteItemIds) {
            $rfq = Rfq::query()->lockForUpdate()->findOrFail($rfq->id);
            if (!in_array($rfq->status, ['responses_received','evaluated','partially_awarded'], true)) {
                throw ValidationException::withMessages(['status'=>['Only an open evaluated RFQ can be awarded.']]);
            }
            $items=SupplierQuoteItem::query()->with(['supplierQuote','requestItem.product'])->whereIn('id',$quoteItemIds)->get();
            if ($items->count() !== count(array_unique($quoteItemIds)) || $items->isEmpty() || $items->contains(fn($i)=>(int)$i->supplierQuote->rfq_id !== (int)$rfq->id)) {
                throw ValidationException::withMessages(['quote_item_ids'=>['Every selected quote item must belong to this RFQ.']]);
            }
            if ($items->pluck('purchase_request_item_id')->unique()->count() !== $items->count()) {
                throw ValidationException::withMessages(['quote_item_ids'=>['Select only one supplier offer per requested item.']]);
            }
            foreach ($items as $item) {
                $quote = $item->supplierQuote;
                if ($quote->valid_until && $quote->valid_until->copy()->endOfDay()->isPast()) {
                    throw ValidationException::withMessages(['quote_item_ids'=>['An expired supplier quote cannot be awarded.']]);
                }
                $latest = SupplierQuote::query()->where('rfq_id',$rfq->id)->where('supplier_id',$quote->supplier_id)->max('revision');
                if ((int)$quote->revision !== (int)$latest) throw ValidationException::withMessages(['quote_item_ids'=>['A newer supplier quote is available. Review it before awarding.']]);
                if ($item->minimum_order_quantity && \Brick\Math\BigDecimal::of($item->offered_quantity)->isLessThan($item->minimum_order_quantity)) {
                    throw ValidationException::withMessages(['quote_item_ids'=>['Offered quantity is below the supplier minimum order quantity.']]);
                }
                app(UnitConversionService::class)->assertPrecision((float)$item->offered_quantity, $item->requestItem->unit, 'quote_item_ids');
                if (ProcurementAward::query()->where('rfq_id',$rfq->id)->where('purchase_request_item_id',$item->purchase_request_item_id)->exists()) {
                    throw ValidationException::withMessages(['quote_item_ids'=>['A requested item was already awarded; it cannot be awarded again.']]);
                }
                ProcurementAward::create(['company_id'=>$rfq->company_id,'rfq_id'=>$rfq->id,'purchase_request_item_id'=>$item->purchase_request_item_id,'supplier_quote_item_id'=>$item->id,'status'=>'selected','selected_by'=>Auth::id(),'selected_at'=>now()]);
                $quote->update(['status'=>'accepted']);
            }
            $complete = ProcurementAward::query()->where('rfq_id',$rfq->id)->count() === $rfq->purchaseRequest->items()->count();
            $rfq->update(['status'=>$complete ? 'awarded' : 'partially_awarded']);
            $rfq->purchaseRequest->update(['status'=>$complete ? 'completed' : 'sourcing']);
            $this->audit($rfq,'procurement.rfq.awarded','Selected supplier quote items awarded.');
            return $this->comparison($rfq);
        });
    }
    public function convertAwards(Rfq $rfq,string $idempotencyKey,?int $warehouseId=null): array { return DB::transaction(function()use($rfq,$idempotencyKey,$warehouseId){$rfq=Rfq::query()->lockForUpdate()->findOrFail($rfq->id);$all=ProcurementAward::query()->with(['quoteItem.supplierQuote','requestItem.product'])->where('rfq_id',$rfq->id)->lockForUpdate()->get();$existing=$all->whereNotNull('purchase_order_id');if($existing->where('conversion_key',$idempotencyKey)->isNotEmpty())return $existing->pluck('purchase_order_id')->unique()->values()->all();$awards=$all->where('status','selected');if($awards->isEmpty()&&$existing->isNotEmpty())return $existing->pluck('purchase_order_id')->unique()->values()->all();if($awards->isEmpty())throw ValidationException::withMessages(['awards'=>['Award at least one requested item before conversion.']]);$orders=$existing->pluck('purchase_order_id')->unique()->values()->all();foreach($awards->groupBy(fn($a)=>$a->quoteItem->supplierQuote->supplier_id.'|'.$a->quoteItem->supplierQuote->currency) as $group){$quote=$group->first()->quoteItem->supplierQuote;$po=$this->orders->create(['supplier_id'=>$quote->supplier_id,'warehouse_id'=>$warehouseId,'ordered_at'=>now()->toDateString(),'expected_at'=>now()->addDays((int)$group->max(fn($a)=>$a->quoteItem->lead_time_days??0))->toDateString(),'currency'=>$quote->currency,'exchange_rate'=>$quote->exchange_rate,'exchange_rate_date'=>$quote->exchange_rate_date?->toDateString(),'exchange_rate_source'=>$quote->exchange_rate_source,'status'=>'draft','notes'=>'Created from '.$rfq->rfq_number.' award.','change_reason'=>'Procurement award conversion.','items'=>$group->map(fn($a)=>['product_id'=>$a->requestItem->product_id,'description'=>$a->requestItem->description,'unit'=>$a->requestItem->unit,'quantity'=>$a->quoteItem->offered_quantity,'unit_price'=>$a->quoteItem->unit_price])->all()]);foreach($group as $award)$award->update(['purchase_order_id'=>$po->id,'status'=>'converted','conversion_key'=>$idempotencyKey]);$orders[]=$po->id;}$this->audit($rfq,'procurement.awards.converted','Awarded quote items converted into purchase orders.');return $orders;}); }
    private function number(string $prefix):string{$company=(int)Auth::user()->company_id;return sprintf('%s-%s-%04d',$prefix,now('Europe/Tirane')->format('Y'),PurchaseRequest::withoutGlobalScopes()->where('company_id',$company)->count()+1);} private function audit($entity,string $action,string $description):void{ActivityLog::create(['company_id'=>$entity->company_id,'user_id'=>Auth::id(),'action'=>$action,'entity'=>class_basename($entity),'entity_id'=>$entity->id,'description'=>$description]);}
}
