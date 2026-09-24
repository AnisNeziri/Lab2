<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Services\ProcurementService;
use App\Support\CompanyCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProcurementController extends Controller {
 public function __construct(private readonly ProcurementService $procurement){}
 public function requests():JsonResponse{return response()->json($this->procurement->requests());}
 public function showRequest(PurchaseRequest $purchaseRequest):JsonResponse{$this->owned($purchaseRequest);return response()->json($this->procurement->findRequest($purchaseRequest));}
 public function storeRequest(Request $request):JsonResponse{return response()->json($this->procurement->createRequest($this->requestData($request)),201);}
 public function updateRequest(Request $request,PurchaseRequest $purchaseRequest):JsonResponse{$this->owned($purchaseRequest);return response()->json($this->procurement->updateRequest($purchaseRequest,$this->requestData($request)));}
 public function submit(PurchaseRequest $purchaseRequest):JsonResponse{$this->owned($purchaseRequest);return response()->json($this->procurement->submit($purchaseRequest));}
 public function createRfq(Request $request,PurchaseRequest $purchaseRequest):JsonResponse{$this->owned($purchaseRequest);$data=$request->validate(['supplier_ids'=>['required','array','min:1'],'supplier_ids.*'=>['integer',Rule::exists('suppliers','id')->where('company_id',auth()->user()->company_id)],'response_due_at'=>['nullable','date'],'notes'=>['nullable','string','max:2000']]);return response()->json($this->procurement->createRfq($purchaseRequest,$data),201);}
 public function issueRfq(Rfq $rfq):JsonResponse{$this->owned($rfq);return response()->json($this->procurement->issueRfq($rfq));}
 public function comparison(Rfq $rfq):JsonResponse{$this->owned($rfq);return response()->json($this->procurement->comparison($rfq));}
 public function quote(Request $request,Rfq $rfq):JsonResponse{$this->owned($rfq);$base=CompanyCurrency::current();$data=$request->validate(['supplier_id'=>['required','integer',Rule::exists('suppliers','id')->where('company_id',auth()->user()->company_id)],'currency'=>['required',Rule::in(CompanyCurrency::accepted($base))],'exchange_rate'=>['nullable','numeric','gt:0'],'exchange_rate_date'=>['nullable','date'],'exchange_rate_source'=>['nullable','string','max:255'],'payment_terms'=>['nullable','string','max:255'],'shipping_terms'=>['nullable','string','max:255'],'valid_until'=>['nullable','date'],'notes'=>['nullable','string','max:2000'],'items'=>['required','array','min:1'],'items.*.purchase_request_item_id'=>['required','integer'],'items.*.offered_quantity'=>['required','numeric','min:0.001'],'items.*.unit_price'=>['required','numeric','min:0'],'items.*.minimum_order_quantity'=>['nullable','numeric','min:0.001'],'items.*.lead_time_days'=>['nullable','integer','min:0','max:3650'],'items.*.notes'=>['nullable','string','max:1000']]);$data['currency']=strtoupper($data['currency']);$data['exchange_rate']=$data['exchange_rate']??($data['currency']===$base?1:null);if(!$data['exchange_rate'])return response()->json(['message'=>'Exchange rate is required for a non-base currency.'],422);return response()->json($this->procurement->createQuote($rfq,$data),201);}
 public function award(Request $request,Rfq $rfq):JsonResponse{$this->owned($rfq);$data=$request->validate(['quote_item_ids'=>['required','array','min:1'],'quote_item_ids.*'=>['integer']]);return response()->json($this->procurement->award($rfq,$data['quote_item_ids']));}
 public function convert(Request $request,Rfq $rfq):JsonResponse{$this->owned($rfq);$data=$request->validate(['idempotency_key'=>['required','uuid'],'warehouse_id'=>['nullable','integer',Rule::exists('warehouses','id')->where('company_id',auth()->user()->company_id)]]);$ids=$this->procurement->convertAwards($rfq,$data['idempotency_key'],$data['warehouse_id']??null);$orders=PurchaseOrder::query()->with('supplier:id,name')->whereIn('id',$ids)->get(['id','supplier_id','po_number','currency','total_amount','status'])->map(fn($po)=>['id'=>$po->id,'po_number'=>$po->po_number,'supplier'=>['id'=>$po->supplier?->id,'name'=>$po->supplier?->name],'currency'=>$po->currency,'total'=>$po->getRawOriginal('total_amount'),'status'=>$po->status])->values();return response()->json(['purchase_order_ids'=>$ids,'purchase_orders'=>$orders]);}
 private function requestData(Request $request):array{$base=CompanyCurrency::current();return $request->validate(['requested_at'=>['nullable','date'],'required_by'=>['nullable','date'],'notes'=>['nullable','string','max:2000'],'currency'=>['required',Rule::in(CompanyCurrency::accepted($base))],'items'=>['required','array','min:1'],'items.*.product_id'=>['nullable','integer',Rule::exists('products','id')->where('company_id',auth()->user()->company_id)],'items.*.description'=>['required','string','max:255'],'items.*.unit'=>['required','string','max:50'],'items.*.quantity'=>['required','numeric','min:0.001'],'items.*.estimated_unit_price'=>['nullable','numeric','min:0']]);}
 private function owned(object $record):void{if((int)$record->company_id!==(int)auth()->user()->company_id)abort(404);}
}
