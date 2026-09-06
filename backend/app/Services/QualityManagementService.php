<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\GoodsReceiptItem;
use App\Models\OperationalException;
use App\Models\Product;
use App\Models\QualityAttachment;
use App\Models\QualityDefect;
use App\Models\QualityDefectCategory;
use App\Models\QualityInspection;
use App\Models\QualityInspectionTemplate;
use App\Models\StockMovement;
use App\Models\SupplierClaim;
use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QualityManagementService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly BusinessEventService $events,
    ) {}

    public function inspections(array $filters): LengthAwarePaginator
    {
        return QualityInspection::query()
            ->with(['supplier:id,name','product:id,name,sku,unit','goodsReceipt:id,receipt_number','purchaseOrder:id,po_number,total_amount,total_paid,due_at,status','warehouse:id,name,code','inspector:id,name','defects.category'])
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['supplier_id'] ?? null, fn ($q, $value) => $q->where('supplier_id', $value))
            ->when($filters['product_id'] ?? null, fn ($q, $value) => $q->where('product_id', $value))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('inspection_date', '>=', $value))
            ->when($filters['to'] ?? null, fn ($q, $value) => $q->whereDate('inspection_date', '<=', $value))
            ->latest('inspection_date')->latest('id')->paginate($filters['per_page'] ?? 25);
    }

    public function show(QualityInspection $inspection): QualityInspection
    {
        return $inspection->load([
            'supplier','purchaseOrder','purchaseOrderItem','goodsReceipt','goodsReceiptItem','product',
            'inventoryLot','warehouse','location','template.items','inspector','finalizer','revisionOf',
            'results','defects.category','attachments',
        ]);
    }

    public function modeForProduct(Product $product, ?int $supplierId = null): array
    {
        $product->loadMissing(['category','supplier']);
        $receiptSupplier = $supplierId ? Supplier::query()->find($supplierId) : $product->supplier;
        $sources = [
            ['mode' => $product->quality_inspection_mode ?? 'not_required', 'template_id' => $product->quality_inspection_template_id],
            ['mode' => $product->category?->quality_inspection_mode ?? 'not_required', 'template_id' => $product->category?->quality_inspection_template_id],
            ['mode' => $receiptSupplier?->quality_inspection_mode ?? 'not_required', 'template_id' => $receiptSupplier?->quality_inspection_template_id],
        ];
        $mode = collect($sources)->contains(fn ($source) => $source['mode'] === 'required')
            ? 'required'
            : (collect($sources)->contains(fn ($source) => $source['mode'] === 'optional') ? 'optional' : 'not_required');
        $template = collect($sources)->first(fn ($source) => $source['mode'] === $mode && $source['template_id']);

        return ['mode' => $mode, 'template_id' => $template['template_id'] ?? null];
    }

    public function createForReceiptItem(GoodsReceiptItem $item, array $data = [], bool $alreadyQuarantined = false): QualityInspection
    {
        return DB::transaction(function () use ($item, $data, $alreadyQuarantined): QualityInspection {
            $item = GoodsReceiptItem::query()->with(['receipt.purchaseOrder','product.category','product.supplier'])->lockForUpdate()->findOrFail($item->id);
            if ($item->qualityInspection && ! $item->qualityInspection->finalized_at) return $this->show($item->qualityInspection);
            $received = round((float) ($data['received_quantity'] ?? ((float) $item->accepted_base_quantity + (float) $item->damaged_base_quantity)), 3);
            $inspected = round((float) ($data['inspected_quantity'] ?? $received), 3);
            if ($received <= 0 || $inspected <= 0 || $inspected > $received + .0005) {
                throw ValidationException::withMessages(['inspected_quantity' => ['The sample must be greater than zero and cannot exceed the received quantity.']]);
            }
            $config = $this->modeForProduct($item->product, $item->receipt->purchaseOrder->supplier_id);
            $trace = [];
            if (! $alreadyQuarantined) {
                $trace = $this->stageReceiptItemInQuarantine($item);
            } else {
                $trace = $this->receiptTrace($item, 'quarantine');
            }
            $inspection = QualityInspection::create([
                'company_id' => $item->receipt->company_id,
                'inspection_number' => $this->nextNumber('QI'),
                'supplier_id' => $item->receipt->purchaseOrder->supplier_id,
                'purchase_order_id' => $item->receipt->purchase_order_id,
                'purchase_order_item_id' => $item->purchase_order_item_id,
                'goods_receipt_id' => $item->goods_receipt_id,
                'goods_receipt_item_id' => $item->id,
                'product_id' => $item->product_id,
                'inventory_lot_id' => count($trace) === 1 ? ($trace[0]['inventory_lot_id'] ?? null) : null,
                'warehouse_id' => $item->receipt->warehouse_id,
                'location_id' => $item->receipt->location_id,
                'quality_inspection_template_id' => $data['quality_inspection_template_id'] ?? $config['template_id'],
                'inspector_id' => $data['inspector_id'] ?? Auth::id(),
                'inspection_date' => $data['inspection_date'] ?? now(),
                'inspection_scope' => $data['inspection_scope'] ?? ($inspected < $received ? 'sample' : 'whole_receipt'),
                'source_stock_state' => 'quarantine',
                'received_quantity' => $received,
                'inspected_quantity' => $inspected,
                'status' => 'PENDING',
                'notes' => $data['notes'] ?? null,
                'trace_allocations' => $trace,
                'created_by' => Auth::id(),
            ]);
            $item->update(['quality_inspection_mode_snapshot' => $config['mode'], 'quality_inspection_id' => $inspection->id]);
            $this->audit($inspection, 'quality.inspection.created', 'Incoming-goods inspection created.', ['goods_receipt_item_id' => $item->id, 'received_quantity' => $received]);
            $this->events->record('quality.inspection_created', $inspection, $inspection->inspection_number, ['receipt_id'=>$inspection->goods_receipt_id,'product_id'=>$inspection->product_id]);

            return $this->show($inspection);
        });
    }

    public function finalize(QualityInspection $inspection, array $data): QualityInspection
    {
        return DB::transaction(function () use ($inspection, $data): QualityInspection {
            $inspection = QualityInspection::query()->with(['template.items','product'])->lockForUpdate()->findOrFail($inspection->id);
            if ($inspection->finalized_at) throw ValidationException::withMessages(['inspection' => ['This inspection is finalized. Create a correction revision instead of rewriting history.']]);
            $quantities = collect(['accepted_quantity','rejected_quantity','quarantine_quantity','damaged_quantity'])
                ->mapWithKeys(fn ($key) => [$key => round((float) ($data[$key] ?? 0), 3)]);
            if ($quantities->contains(fn ($value) => $value < 0)) throw ValidationException::withMessages(['quantities' => ['Disposition quantities cannot be negative.']]);
            $total = round((float) $quantities->sum(), 3);
            if (abs($total - (float) $inspection->received_quantity) > .0005) {
                throw ValidationException::withMessages(['quantities' => ["Accepted, rejected, quarantine and damaged quantities must total {$inspection->received_quantity}."]]);
            }
            $submitted = collect($data['results'] ?? [])->keyBy(fn ($result) => (string) ($result['quality_checklist_item_id'] ?? ''));
            foreach ($inspection->template?->items ?? [] as $check) {
                $result = $submitted->get((string) $check->id, []);
                if ($check->is_required && $this->emptyResult($check->check_type, $result)) {
                    throw ValidationException::withMessages(['results' => ["Complete the required check: {$check->name}."]]);
                }
                if ($result !== []) {
                    $numeric = isset($result['numeric_value']) && $result['numeric_value'] !== '' ? (float) $result['numeric_value'] : null;
                    $passed = array_key_exists('passed', $result) ? (bool) $result['passed'] : null;
                    if ($check->check_type === 'numeric' && $numeric !== null) {
                        $low = $check->minimum_value !== null ? (float) $check->minimum_value - (float) ($check->tolerance ?? 0) : null;
                        $high = $check->maximum_value !== null ? (float) $check->maximum_value + (float) ($check->tolerance ?? 0) : null;
                        $passed = ($low === null || $numeric >= $low) && ($high === null || $numeric <= $high);
                    }
                    $inspection->results()->create([
                        'quality_checklist_item_id'=>$check->id,'check_name'=>$check->name,'check_type'=>$check->check_type,
                        'passed'=>$passed,'numeric_value'=>$numeric,'text_value'=>$result['text_value'] ?? null,
                        'minimum_value'=>$check->minimum_value,'maximum_value'=>$check->maximum_value,'tolerance'=>$check->tolerance,
                        'notes'=>$result['notes'] ?? null,
                    ]);
                }
            }

            $remainingTrace = $inspection->trace_allocations ?? [];
            foreach ([['accepted_quantity','available'], ['damaged_quantity','damaged']] as [$field, $target]) {
                $quantity = (float) $quantities[$field];
                if ($quantity <= 0) continue;
                if ($remainingTrace === []) {
                    $allocated = [];
                } else {
                    [$allocated, $remainingTrace] = $this->takeTrace($remainingTrace, $quantity);
                }
                $this->stock->transitionState([
                    'product_id'=>$inspection->product_id,'warehouse_id'=>$inspection->warehouse_id,'location_id'=>$inspection->location_id,
                    'from_state'=>'quarantine','to_state'=>$target,'quantity'=>$quantity,
                    'reason'=>"Quality disposition {$inspection->inspection_number}",'source_type'=>'quality_inspection','source_id'=>$inspection->id,
                    'idempotency_key'=>"quality-{$inspection->id}-{$target}",'trace_allocations'=>$allocated,
                    'metadata'=>['inspection_number'=>$inspection->inspection_number],
                ]);
            }
            $status = $this->outcome($quantities->all());
            $inspection->update([
                ...$quantities->all(),'status'=>$status,'decision'=>$data['decision'] ?? strtolower($status),
                'notes'=>$data['notes'] ?? $inspection->notes,'inspector_id'=>$data['inspector_id'] ?? $inspection->inspector_id ?? Auth::id(),
                'finalized_at'=>now(),'finalized_by'=>Auth::id(),
            ]);
            $this->audit($inspection, 'quality.inspection.finalized', 'Quality decision finalized.', ['status'=>$status,'quantities'=>$quantities->all()]);
            $this->events->record('quality.inspection_finalized', $inspection, $inspection->inspection_number, ['status'=>$status,'quantities'=>$quantities->all()]);
            $this->syncInspectionException($inspection);

            return $this->show($inspection->fresh());
        });
    }

    public function correction(QualityInspection $original, array $data): QualityInspection
    {
        if (! $original->finalized_at) throw ValidationException::withMessages(['inspection' => ['Only a finalized inspection needs a correction revision.']]);
        return DB::transaction(function () use ($original, $data): QualityInspection {
            $original = QualityInspection::query()->with('product')->lockForUpdate()->findOrFail($original->id);
            $existing = QualityInspection::query()->where('revision_of_id',$original->id)->whereNull('finalized_at')->first();
            if ($existing) return $this->show($existing);
            $revision = $original->replicate(['inspection_number','status','decision','finalized_at','finalized_by']);
            $revision->fill([
                'inspection_number'=>$this->nextNumber('QI-C'),'revision_of_id'=>$original->id,'status'=>'PENDING',
                'accepted_quantity'=>0,'rejected_quantity'=>0,'quarantine_quantity'=>$original->received_quantity,
                'damaged_quantity'=>0,'notes'=>$data['reason'] ?? null,'inspection_date'=>now(),'created_by'=>Auth::id(),
                'trace_allocations'=>$original->trace_allocations ?? [],
            ])->save();
            $trace = $original->trace_allocations ?? [];
            foreach ([['accepted_quantity','available'],['damaged_quantity','damaged']] as [$field,$source]) {
                $quantity=(float)$original->{$field};
                if($quantity<=0) continue;
                if($trace===[])$allocated=[];else{[$allocated,$trace]=$this->takeTrace($trace,$quantity);}
                $this->stock->transitionState([
                    'product_id'=>$original->product_id,'warehouse_id'=>$original->warehouse_id,'location_id'=>$original->location_id,
                    'from_state'=>$source,'to_state'=>'quarantine','quantity'=>$quantity,
                    'reason'=>"Quality correction staging for {$original->inspection_number}",'source_type'=>'quality_inspection_correction',
                    'source_id'=>$revision->id,'idempotency_key'=>"quality-correction-stage-{$revision->id}-{$source}",'trace_allocations'=>$allocated,
                ]);
            }
            $this->audit($revision, 'quality.inspection.correction_created', 'Correction revision created without changing finalized history.', ['revision_of_id'=>$original->id,'reason'=>$data['reason'] ?? null]);
            return $this->show($revision);
        });
    }

    public function saveTemplate(array $data, ?QualityInspectionTemplate $template = null): QualityInspectionTemplate
    {
        return DB::transaction(function () use ($data, $template): QualityInspectionTemplate {
            $template ??= new QualityInspectionTemplate(['company_id'=>Auth::user()->company_id,'created_by'=>Auth::id()]);
            $template->fill(['name'=>$data['name'],'description'=>$data['description'] ?? null,'is_active'=>$data['is_active'] ?? true,'updated_by'=>Auth::id()])->save();
            $template->items()->delete();
            foreach ($data['items'] as $index => $item) $template->items()->create([...$item,'sort_order'=>$item['sort_order'] ?? $index]);
            return $template->fresh('items');
        });
    }

    public function createDefect(QualityInspection $inspection, array $data): QualityDefect
    {
        if ($data['affected_quantity'] > (float) $inspection->received_quantity + .0005) throw ValidationException::withMessages(['affected_quantity'=>['Affected quantity cannot exceed the inspected receipt quantity.']]);
        $defect = QualityDefect::create([
            ...$data,'company_id'=>$inspection->company_id,'quality_inspection_id'=>$inspection->id,'supplier_id'=>$inspection->supplier_id,
            'goods_receipt_id'=>$inspection->goods_receipt_id,'product_id'=>$inspection->product_id,'inventory_lot_id'=>$inspection->inventory_lot_id,
            'discovered_at'=>$data['discovered_at'] ?? now(),'discovered_by'=>Auth::id(),
        ]);
        if ($defect->severity === 'CRITICAL') $this->upsertException($defect, 'critical_defect', 'critical', "Critical defect found for {$inspection->product->name}.", "/quality?inspection={$inspection->id}");
        $this->events->record('quality.defect_recorded', $defect, $inspection->inspection_number, ['severity'=>$defect->severity,'affected_quantity'=>$defect->affected_quantity]);
        return $defect->load('category');
    }

    public function createClaim(array $data): SupplierClaim
    {
        return DB::transaction(function () use ($data): SupplierClaim {
            $inspection = ! empty($data['quality_inspection_id']) ? QualityInspection::query()->with('defects')->findOrFail($data['quality_inspection_id']) : null;
            if ($inspection && (int) $inspection->supplier_id !== (int) $data['supplier_id']) throw ValidationException::withMessages(['supplier_id'=>['The claim supplier must match the inspection supplier.']]);
            $claim = SupplierClaim::create([
                'company_id'=>Auth::user()->company_id,'claim_number'=>$this->nextNumber('SC'),'supplier_id'=>$data['supplier_id'],
                'purchase_order_id'=>$data['purchase_order_id'] ?? $inspection?->purchase_order_id,'goods_receipt_id'=>$data['goods_receipt_id'] ?? $inspection?->goods_receipt_id,
                'quality_inspection_id'=>$inspection?->id,'status'=>'OPEN','requested_outcome'=>$data['requested_outcome'],
                'claim_date'=>$data['claim_date'] ?? now()->toDateString(),'expected_resolution_date'=>$data['expected_resolution_date'] ?? null,
                'affected_value'=>0,'currency'=>strtoupper($data['currency'] ?? 'EUR'),'communication_notes'=>$data['communication_notes'] ?? null,
                'created_by'=>Auth::id(),
            ]);
            $total = 0;
            foreach ($data['items'] as $line) {
                $receiptItem = ! empty($line['goods_receipt_item_id'])
                    ? GoodsReceiptItem::query()->whereHas('receipt.purchaseOrder',fn($q)=>$q->where('supplier_id',$data['supplier_id']))->findOrFail($line['goods_receipt_item_id'])
                    : null;
                if ($receiptItem && (int)$receiptItem->product_id !== (int)$line['product_id']) {
                    throw ValidationException::withMessages(['items'=>['A claimed receipt line must match the selected product.']]);
                }
                $unit = round((float) ($line['unit_value'] ?? $receiptItem?->base_purchase_unit_cost ?? 0), 6);
                $value = round((float) $line['affected_quantity'] * $unit, 2);
                $claim->items()->create([...$line,'unit_value'=>$unit,'line_value'=>$value]);
                $total += $value;
            }
            $claim->update(['affected_value'=>round($total, 2)]);
            $defectIds = array_values(array_unique($data['defect_ids'] ?? []));
            if ($defectIds !== []) {
                $valid = QualityDefect::query()->whereIn('id',$defectIds)->where('supplier_id',$data['supplier_id'])->pluck('id')->all();
                if (count($valid) !== count($defectIds)) throw ValidationException::withMessages(['defect_ids'=>['Every linked defect must belong to the same supplier.']]);
                $claim->defects()->sync($valid);
            }
            $this->audit($claim, 'quality.supplier_claim.created', 'Supplier claim created.', ['affected_value'=>$total,'defect_ids'=>$defectIds]);
            $this->events->record('supplier.claim_created', $claim, $claim->claim_number, ['supplier_id'=>$claim->supplier_id,'affected_value'=>$total]);
            return $this->showClaim($claim);
        });
    }

    public function resolveClaim(SupplierClaim $claim, array $data): SupplierClaim
    {
        if ($claim->resolved_at) throw ValidationException::withMessages(['claim'=>['This claim is already resolved; its decision history is immutable.']]);
        if (! empty($data['inventory_return_id'])) {
            $validReturn = \App\Models\InventoryReturn::query()->whereKey($data['inventory_return_id'])
                ->where('type','supplier')->where('supplier_id',$claim->supplier_id)
                ->when($claim->goods_receipt_id,fn($q)=>$q->where('goods_receipt_id',$claim->goods_receipt_id))
                ->where('status','completed')->exists();
            if (! $validReturn) throw ValidationException::withMessages(['inventory_return_id'=>['Link a completed supplier return for this claim and source receipt. Supplier returns must use the normal approved inventory-return workflow.']]);
        }
        if (($data['actual_resolution'] ?? null) === 'goods_returned' && empty($data['inventory_return_id'])) {
            throw ValidationException::withMessages(['inventory_return_id'=>['Complete and link the supplier inventory return before resolving the claim as goods returned.']]);
        }
        $claim->update([
            'status'=>'RESOLVED','actual_resolution'=>$data['actual_resolution'],'financial_amount'=>$data['financial_amount'] ?? null,
            'resolution_notes'=>$data['resolution_notes'] ?? null,'inventory_return_id'=>$data['inventory_return_id'] ?? null,
            'resolved_at'=>now(),'resolved_by'=>Auth::id(),
        ]);
        $this->audit($claim, 'quality.supplier_claim.resolved', 'Supplier claim resolved.', ['actual_resolution'=>$claim->actual_resolution,'financial_amount'=>$claim->financial_amount]);
        $this->events->record('supplier.claim_resolved', $claim, $claim->claim_number, ['resolution'=>$claim->actual_resolution,'inventory_return_id'=>$claim->inventory_return_id]);
        OperationalException::query()->where('exception_key', "supplier_claim_overdue:{$claim->id}")->where('status','active')->update(['status'=>'resolved','resolved_at'=>now(),'resolved_by'=>Auth::id()]);
        return $this->showClaim($claim->fresh());
    }

    public function addClaimCommunication(SupplierClaim $claim, string $note): SupplierClaim
    {
        if ($claim->resolved_at) throw ValidationException::withMessages(['claim'=>['Resolved claim communication history cannot be changed.']]);
        $entry='['.now('Europe/Tirane')->format('Y-m-d H:i').'] '.Auth::user()->name.': '.trim($note);
        $claim->update(['communication_notes'=>trim(implode("\n",array_filter([$claim->communication_notes,$entry])))]);
        $this->audit($claim,'quality.supplier_claim.communication','Supplier claim communication added.',['entry'=>$entry]);
        return $this->showClaim($claim->fresh());
    }

    public function claims(array $filters): LengthAwarePaginator
    {
        return SupplierClaim::query()->with(['supplier:id,name','inspection:id,inspection_number','items.product:id,name,sku','defects.category','inventoryReturn:id,return_number,status'])
            ->when($filters['status'] ?? null, fn ($q,$v) => $q->where('status',$v))
            ->when($filters['supplier_id'] ?? null, fn ($q,$v) => $q->where('supplier_id',$v))
            ->latest('claim_date')->paginate($filters['per_page'] ?? 25);
    }

    public function showClaim(SupplierClaim $claim): SupplierClaim
    {
        return $claim->load(['supplier','purchaseOrder','goodsReceipt','inspection','items.product','defects.category','attachments','inventoryReturn']);
    }

    public function addAttachment(array $data, $file): QualityAttachment
    {
        $contents = $file->get();
        return QualityAttachment::create([
            'company_id'=>Auth::user()->company_id,'quality_inspection_id'=>$data['quality_inspection_id'] ?? null,
            'supplier_claim_id'=>$data['supplier_claim_id'] ?? null,'document_type'=>$data['document_type'] ?? 'evidence',
            'filename'=>$file->getClientOriginalName(),'mime_type'=>$file->getMimeType() ?: 'application/octet-stream',
            'file_size'=>strlen($contents),'sha256'=>hash('sha256',$contents),'file_data'=>base64_encode($contents),'uploaded_by'=>Auth::id(),
        ]);
    }

    public function dashboard(array $filters = []): array
    {
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->endOfMonth()->toDateString();
        $this->syncOverdueClaimExceptions();
        $this->syncOverdueInspectionExceptions();
        $inspections = QualityInspection::query()->whereDate('inspection_date','>=',$from)->whereDate('inspection_date','<=',$to);
        $defects = QualityDefect::query()->whereDate('discovered_at','>=',$from)->whereDate('discovered_at','<=',$to);
        $claims = SupplierClaim::query();
        return [
            'period'=>['from'=>$from,'to'=>$to],
            'pending_inspections'=>(clone $inspections)->where('status','PENDING')->count(),
            'failed_inspections'=>(clone $inspections)->where('status','FAILED')->count(),
            'quarantined_quantity'=>round((float) QualityInspection::query()->get()->sum(fn($inspection)=>$inspection->status==='PENDING'?(float)$inspection->received_quantity:((float)$inspection->quarantine_quantity+(float)$inspection->rejected_quantity)),3),
            'defects_this_period'=>(clone $defects)->count(),
            'open_claims'=>(clone $claims)->where('status','!=','RESOLVED')->count(),
            'open_claim_value'=>round((float) (clone $claims)->where('status','!=','RESOLVED')->sum('affected_value'),2),
            'worst_defect_categories'=>(clone $defects)->leftJoin('quality_defect_categories as c','c.id','=','quality_defects.quality_defect_category_id')->selectRaw("COALESCE(c.name, 'Uncategorized') as name, COUNT(*) as defect_count, SUM(affected_quantity) as affected_quantity")->groupBy('c.id','c.name')->orderByDesc('defect_count')->limit(5)->get(),
            'suppliers_highest_defect_rates'=>app(SupplierPerformanceService::class)->ranking(5),
        ];
    }

    private function stageReceiptItemInQuarantine(GoodsReceiptItem $item): array
    {
        $combined = [];
        foreach ([['available',(float)$item->accepted_base_quantity],['damaged',(float)$item->damaged_base_quantity]] as [$state,$quantity]) {
            if ($quantity <= 0) continue;
            $trace = $this->receiptTrace($item, $state);
            $result = $this->stock->transitionState([
                'product_id'=>$item->product_id,'warehouse_id'=>$item->receipt->warehouse_id,'location_id'=>$item->receipt->location_id,
                'from_state'=>$state,'to_state'=>'quarantine','quantity'=>$quantity,'reason'=>"Quality inspection staging for {$item->receipt->receipt_number}",
                'source_type'=>'quality_inspection_staging','source_id'=>$item->id,'idempotency_key'=>"quality-stage-receipt-item-{$item->id}-{$state}",
                'trace_allocations'=>$trace,
            ]);
            foreach ($result['in_movement']->traceLines as $line) $combined[]=['inventory_lot_id'=>$line->inventory_lot_id,'quantity'=>(float)$line->quantity];
        }
        return $this->mergeTrace($combined);
    }

    private function receiptTrace(GoodsReceiptItem $item, string $state): array
    {
        return StockMovement::query()->with('traceLines')->where('source_type','goods_receipt')->where('source_id',$item->goods_receipt_id)
            ->where('product_id',$item->product_id)->where('stock_state',$state)->where('type','in')->get()
            ->flatMap(fn ($movement) => $movement->traceLines->map(fn ($line) => ['inventory_lot_id'=>$line->inventory_lot_id,'quantity'=>(float)$line->quantity]))->values()->all();
    }

    private function mergeTrace(array $rows): array
    {
        return collect($rows)->groupBy('inventory_lot_id')->map(fn ($items,$id) => ['inventory_lot_id'=>(int)$id,'quantity'=>round((float)$items->sum('quantity'),3)])->values()->all();
    }

    private function takeTrace(array $rows, float $quantity): array
    {
        $picked=[];$remaining=[];$needed=round($quantity,3);
        foreach($rows as $row){$available=round((float)$row['quantity'],3);$take=min($available,$needed);if($take>.0005){$picked[]=['inventory_lot_id'=>$row['inventory_lot_id'],'quantity'=>$take];$needed=round($needed-$take,3);}if($available-$take>.0005)$remaining[]=['inventory_lot_id'=>$row['inventory_lot_id'],'quantity'=>round($available-$take,3)];}
        if($needed>.0005) throw ValidationException::withMessages(['trace_allocations'=>['The inspection lot allocation no longer reconciles with physical stock.']]);
        return [$picked,$remaining];
    }

    private function emptyResult(string $type, array $result): bool
    {
        return match($type){'pass_fail'=>!array_key_exists('passed',$result),'numeric'=>!isset($result['numeric_value'])||$result['numeric_value']==='',default=>trim((string)($result['text_value']??''))===''};
    }

    private function outcome(array $q): string
    {
        if($q['accepted_quantity'] >= array_sum($q)-.0005) return 'PASSED';
        if($q['accepted_quantity']>0) return 'PARTIAL';
        if($q['quarantine_quantity']>0 && $q['rejected_quantity']<=0 && $q['damaged_quantity']<=0) return 'QUARANTINED';
        return 'FAILED';
    }

    private function nextNumber(string $prefix): string
    {
        $company=(int)Auth::user()->company_id;$year=now()->format('Y');
        $table=$prefix==='SC'?'supplier_claims':'quality_inspections';$column=$prefix==='SC'?'claim_number':'inspection_number';
        $count=DB::table($table)->where('company_id',$company)->where($column,'like',"{$prefix}-{$year}-%")->count()+1;
        return sprintf('%s-%s-%05d',$prefix,$year,$count);
    }

    private function audit($entity,string $action,string $description,array $values=[]): void
    {
        ActivityLog::create(['company_id'=>$entity->company_id,'user_id'=>Auth::id(),'action'=>$action,'entity'=>class_basename($entity),'entity_id'=>$entity->id,'description'=>$description,'new_value'=>$values]);
    }

    private function syncInspectionException(QualityInspection $inspection): void
    {
        $key="quality_quarantine:{$inspection->id}";
        if((float)$inspection->quarantine_quantity>0||(float)$inspection->rejected_quantity>0){$this->upsertException($inspection,'quality_quarantine','warning',"Quality decision left stock in quarantine for {$inspection->product->name}.","/quality?inspection={$inspection->id}",$key);}else{OperationalException::query()->where('exception_key',$key)->where('status','active')->update(['status'=>'resolved','resolved_at'=>now()]);}
    }

    private function syncOverdueClaimExceptions(): void
    {
        SupplierClaim::query()->where('status','!=','RESOLVED')->whereDate('expected_resolution_date','<',now()->toDateString())->each(fn($claim)=>$this->upsertException($claim,'supplier_claim_overdue','warning',"Supplier claim {$claim->claim_number} is overdue.","/quality?claim={$claim->id}","supplier_claim_overdue:{$claim->id}"));
    }

    private function syncOverdueInspectionExceptions(): void
    {
        QualityInspection::query()->where('status','PENDING')->where('created_at','<',now()->subDays(2))->each(fn($inspection)=>$this->upsertException($inspection,'inspection_overdue','warning',"Inspection {$inspection->inspection_number} is still pending.","/quality?inspection={$inspection->id}","inspection_overdue:{$inspection->id}"));
    }

    private function upsertException($entity,string $type,string $severity,string $description,string $url,?string $key=null): void
    {
        OperationalException::withoutGlobalScopes()->updateOrCreate(['company_id'=>$entity->company_id,'exception_key'=>$key??"{$type}:{$entity->id}"],[
            'exception_type'=>$type,'severity'=>$severity,'entity_type'=>class_basename($entity),'entity_id'=>$entity->id,
            'status'=>'active','detected_at'=>now(),'resolved_at'=>null,'description'=>$description,'relevant_values'=>[],
            'next_action'=>['label'=>'Open Quality Management','url'=>$url],
        ]);
    }
}
