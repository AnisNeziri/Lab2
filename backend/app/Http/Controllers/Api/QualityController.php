<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\QualityAttachment;
use App\Models\QualityDefectCategory;
use App\Models\QualityInspection;
use App\Models\QualityInspectionTemplate;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use App\Services\QualityManagementService;
use App\Services\SupplierPerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class QualityController extends Controller
{
    public function __construct(
        private readonly QualityManagementService $quality,
        private readonly SupplierPerformanceService $performance,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json($this->quality->dashboard($request->validate(['from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from']])));
    }

    public function inspections(Request $request): JsonResponse
    {
        return response()->json($this->quality->inspections($request->validate([
            'status'=>['nullable',Rule::in(['PENDING','PASSED','PARTIAL','FAILED','QUARANTINED'])],
            'supplier_id'=>['nullable','integer'],'product_id'=>['nullable','integer'],'from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from'],'per_page'=>['nullable','integer','min:1','max:100'],
        ])));
    }

    public function sources(): JsonResponse
    {
        return response()->json(\App\Models\GoodsReceipt::query()->with([
            'purchaseOrder:id,po_number,supplier_id,total_amount,total_paid,due_at,status','purchaseOrder.supplier:id,name',
            'warehouse:id,name,code','location:id,name,code,path',
            'items.product:id,name,sku,unit,quality_inspection_mode,quality_inspection_template_id',
            'items.qualityInspection:id,inspection_number,status,finalized_at',
        ])->latest('received_at')->limit(100)->get());
    }

    public function showInspection(QualityInspection $qualityInspection): JsonResponse { $this->owned($qualityInspection); return response()->json($this->quality->show($qualityInspection)); }

    public function createInspection(Request $request): JsonResponse
    {
        $company=(int)$request->user()->company_id;
        $data=$request->validate([
            'goods_receipt_item_id'=>['required','integer',Rule::exists('goods_receipt_items','id')->where(fn($q)=>$q->whereIn('goods_receipt_id',\App\Models\GoodsReceipt::query()->select('id')->where('company_id',$company)))],
            'quality_inspection_template_id'=>['nullable','integer',Rule::exists('quality_inspection_templates','id')->where('company_id',$company)],
            'inspection_date'=>['nullable','date'],'inspection_scope'=>['nullable',Rule::in(['whole_receipt','sample'])],
            'received_quantity'=>['nullable','numeric','gt:0'],'inspected_quantity'=>['nullable','numeric','gt:0'],'notes'=>['nullable','string','max:5000'],
        ]);
        return response()->json($this->quality->createForReceiptItem(GoodsReceiptItem::query()->findOrFail($data['goods_receipt_item_id']),$data),201);
    }

    public function finalize(Request $request, QualityInspection $qualityInspection): JsonResponse
    {
        $this->owned($qualityInspection);
        $company=(int)$request->user()->company_id;
        $data=$request->validate([
            'accepted_quantity'=>['required','numeric','min:0'],'rejected_quantity'=>['required','numeric','min:0'],
            'quarantine_quantity'=>['required','numeric','min:0'],'damaged_quantity'=>['required','numeric','min:0'],
            'decision'=>['nullable','string','max:30'],'notes'=>['nullable','string','max:5000'],
            'inspector_id'=>['nullable','integer',Rule::exists('users','id')->where('company_id',$company)],
            'results'=>['nullable','array'],'results.*.quality_checklist_item_id'=>['required','integer'],
            'results.*.passed'=>['nullable','boolean'],'results.*.numeric_value'=>['nullable','numeric'],'results.*.text_value'=>['nullable','string','max:5000'],'results.*.notes'=>['nullable','string','max:2000'],
        ]);
        return response()->json($this->quality->finalize($qualityInspection,$data));
    }

    public function correction(Request $request, QualityInspection $qualityInspection): JsonResponse
    {
        $this->owned($qualityInspection);
        $data=$request->validate(['reason'=>['required','string','min:5','max:2000']]);
        return response()->json($this->quality->correction($qualityInspection,$data),201);
    }

    public function createDefect(Request $request, QualityInspection $qualityInspection): JsonResponse
    {
        $this->owned($qualityInspection);
        $company=(int)$request->user()->company_id;
        $data=$request->validate([
            'quality_defect_category_id'=>['nullable','integer',Rule::exists('quality_defect_categories','id')->where('company_id',$company)],
            'severity'=>['required',Rule::in(['MINOR','MAJOR','CRITICAL'])],'affected_quantity'=>['required','numeric','gt:0'],
            'description'=>['required','string','min:3','max:5000'],'discovered_at'=>['nullable','date'],
        ]);
        return response()->json($this->quality->createDefect($qualityInspection,$data),201);
    }

    public function templates(): JsonResponse { return response()->json(QualityInspectionTemplate::query()->with('items')->orderBy('name')->get()); }
    public function saveTemplate(Request $request): JsonResponse { return response()->json($this->quality->saveTemplate($this->templateData($request)),201); }
    public function updateTemplate(Request $request, QualityInspectionTemplate $qualityInspectionTemplate): JsonResponse { $this->owned($qualityInspectionTemplate); return response()->json($this->quality->saveTemplate($this->templateData($request),$qualityInspectionTemplate)); }

    public function defectCategories(): JsonResponse { return response()->json(QualityDefectCategory::query()->orderBy('name')->get()); }
    public function saveDefectCategory(Request $request): JsonResponse
    {
        $data=$request->validate(['name'=>['required','string','max:255'],'description'=>['nullable','string','max:2000'],'is_active'=>['nullable','boolean']]);
        return response()->json(QualityDefectCategory::create([...$data,'company_id'=>$request->user()->company_id]),201);
    }

    public function configure(Request $request): JsonResponse
    {
        $company=(int)$request->user()->company_id;
        $data=$request->validate([
            'entity_type'=>['required',Rule::in(['product','category','supplier'])],'entity_id'=>['required','integer'],
            'quality_inspection_mode'=>['required',Rule::in(['not_required','optional','required'])],
            'quality_inspection_template_id'=>['nullable','integer',Rule::exists('quality_inspection_templates','id')->where('company_id',$company)],
        ]);
        $class=match($data['entity_type']){'product'=>Product::class,'category'=>Category::class,'supplier'=>Supplier::class};
        $entity=$class::query()->findOrFail($data['entity_id']);
        $entity->update(['quality_inspection_mode'=>$data['quality_inspection_mode'],'quality_inspection_template_id'=>$data['quality_inspection_template_id']??null]);
        return response()->json($entity);
    }

    public function claims(Request $request): JsonResponse
    {
        return response()->json($this->quality->claims($request->validate(['status'=>['nullable','string','max:30'],'supplier_id'=>['nullable','integer'],'per_page'=>['nullable','integer','min:1','max:100']])));
    }

    public function showClaim(SupplierClaim $supplierClaim): JsonResponse { $this->owned($supplierClaim); return response()->json($this->quality->showClaim($supplierClaim)); }

    public function createClaim(Request $request): JsonResponse
    {
        $company=(int)$request->user()->company_id;
        $data=$request->validate([
            'supplier_id'=>['required','integer',Rule::exists('suppliers','id')->where('company_id',$company)],
            'purchase_order_id'=>['nullable','integer',Rule::exists('purchase_orders','id')->where('company_id',$company)],
            'goods_receipt_id'=>['nullable','integer',Rule::exists('goods_receipts','id')->where('company_id',$company)],
            'quality_inspection_id'=>['nullable','integer',Rule::exists('quality_inspections','id')->where('company_id',$company)],
            'requested_outcome'=>['required',Rule::in(['replacement_requested','refund','supplier_credit','price_reduction','goods_returned'])],
            'claim_date'=>['nullable','date'],'expected_resolution_date'=>['nullable','date','after_or_equal:claim_date'],
            'currency'=>['nullable','string','size:3'],'communication_notes'=>['nullable','string','max:10000'],
            'defect_ids'=>['nullable','array'],'defect_ids.*'=>['integer'],
            'items'=>['required','array','min:1'],'items.*.product_id'=>['required','integer',Rule::exists('products','id')->where('company_id',$company)],
            'items.*.goods_receipt_item_id'=>['nullable','integer'],'items.*.affected_quantity'=>['required','numeric','gt:0'],
            'items.*.unit_value'=>['nullable','numeric','min:0'],'items.*.notes'=>['nullable','string','max:2000'],
        ]);
        return response()->json($this->quality->createClaim($data),201);
    }

    public function resolveClaim(Request $request, SupplierClaim $supplierClaim): JsonResponse
    {
        $this->owned($supplierClaim);
        $company=(int)$request->user()->company_id;
        $data=$request->validate([
            'actual_resolution'=>['required',Rule::in(['replacement_agreed','refund','supplier_credit','price_reduction','goods_returned','claim_rejected','resolved'])],
            'financial_amount'=>['nullable','numeric','min:0'],'resolution_notes'=>['nullable','string','max:10000'],
            'inventory_return_id'=>['nullable','required_if:actual_resolution,goods_returned','integer',Rule::exists('inventory_returns','id')->where('company_id',$company)],
        ]);
        return response()->json($this->quality->resolveClaim($supplierClaim,$data));
    }

    public function claimCommunication(Request $request, SupplierClaim $supplierClaim): JsonResponse
    {
        $this->owned($supplierClaim);
        $data=$request->validate(['note'=>['required','string','min:2','max:5000']]);
        return response()->json($this->quality->addClaimCommunication($supplierClaim,$data['note']));
    }

    public function upload(Request $request): JsonResponse
    {
        $company=(int)$request->user()->company_id;
        $data=$request->validate([
            'quality_inspection_id'=>['nullable','required_without:supplier_claim_id','integer',Rule::exists('quality_inspections','id')->where('company_id',$company)],
            'supplier_claim_id'=>['nullable','required_without:quality_inspection_id','integer',Rule::exists('supplier_claims','id')->where('company_id',$company)],
            'document_type'=>['nullable','string','max:40'],'file'=>['required','file','max:10240','mimes:jpg,jpeg,png,pdf,xlsx,xls,csv,txt,doc,docx'],
        ]);
        return response()->json($this->quality->addAttachment($data,$request->file('file')),201);
    }

    public function download(QualityAttachment $qualityAttachment)
    {
        $this->owned($qualityAttachment);
        if($qualityAttachment->document_version_id)return app(\App\Services\DocumentEvidenceService::class)->download($qualityAttachment->document_version_id);
        $contents=base64_decode((string)$qualityAttachment->getRawOriginal('file_data'),true);
        abort_if($contents===false,422,'Stored evidence is invalid.');
        return response($contents,200,['Content-Type'=>$qualityAttachment->mime_type,'Content-Disposition'=>'attachment; filename="'.str_replace('"','',$qualityAttachment->filename).'"']);
    }

    public function scorecard(Supplier $supplier): JsonResponse { $this->owned($supplier); return response()->json($this->performance->scorecard($supplier)); }
    public function scoreWeights(Request $request): JsonResponse
    {
        return response()->json($this->performance->updateWeights($request->validate(['quality_weight'=>['required','numeric','min:0','max:100'],'delivery_weight'=>['required','numeric','min:0','max:100'],'commercial_weight'=>['required','numeric','min:0','max:100'],'reliability_weight'=>['required','numeric','min:0','max:100']])));
    }

    private function templateData(Request $request): array
    {
        return $request->validate([
            'name'=>['required','string','max:255'],'description'=>['nullable','string','max:2000'],'is_active'=>['nullable','boolean'],
            'items'=>['required','array','min:1'],'items.*.name'=>['required','string','max:255'],'items.*.check_type'=>['required',Rule::in(['pass_fail','numeric','text'])],
            'items.*.unit'=>['nullable','string','max:30'],'items.*.minimum_value'=>['nullable','numeric'],'items.*.maximum_value'=>['nullable','numeric','gte:items.*.minimum_value'],
            'items.*.tolerance'=>['nullable','numeric','min:0'],'items.*.is_required'=>['nullable','boolean'],'items.*.sort_order'=>['nullable','integer','min:0'],'items.*.instructions'=>['nullable','string','max:2000'],
        ]);
    }

    private function owned($model): void
    {
        abort_unless((int)$model->company_id === (int)request()->user()->company_id,404);
    }
}
