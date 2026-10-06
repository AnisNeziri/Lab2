<?php

namespace App\Services;

use App\Jobs\DeliverBusinessEventWebhooks;
use App\Models\BusinessEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BusinessEventService
{
    public function record(
        string $eventType,
        Model $entity,
        ?string $reference = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): ?BusinessEvent {
        try {
            $metadata=array_merge($metadata, AutomationService::eventTrace());
            $companyId = (int) ($entity->company_id ?: Auth::user()?->company_id);
            if ($companyId <= 0) {
                return null;
            }

            $entityType = class_basename($entity);
            $key = Str::limit($idempotencyKey ?: implode(':', [
                $eventType,
                $entityType,
                $entity->getKey(),
                $reference ?: 'none',
            ]), 191, '');

            $event = BusinessEvent::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $companyId, 'idempotency_key' => $key],
                [
                    'event_id' => (string) Str::uuid(),
                    'event_type' => $eventType,
                    'entity_type' => $entityType,
                    'entity_id' => $entity->getKey(),
                    'reference' => $reference,
                    'actor_id' => Auth::id(),
                    'occurred_at' => now(),
                    'metadata' => $this->sanitize($metadata),
                ],
            );

            if ($event->wasRecentlyCreated) {
                if(in_array($entityType,['Product','ProductSupplier','Supplier','StockMovement','DailySale','Invoice','PurchaseOrder','PurchaseRequest','GoodsReceipt','StockTransfer','SalesOrder','Shipment','AnalyticsPrediction','InventoryReservation','SupplierDeliveryRisk','DecisionLearningRecord'],true)&&!str_starts_with($eventType,'optimizer.')){
                    $optimizerDirty=fn()=>\Illuminate\Support\Facades\Cache::put('optimizer-dirty:'.$companyId,(string)Str::uuid(),86400);
                    DB::transactionLevel()>0?DB::afterCommit($optimizerDirty):$optimizerDirty();
                }
                if(in_array($entityType,['DailySale','Invoice','InvoicePayment','InventoryReturn','Customer','CustomerDebtTransaction','Product','StockMovement','SalesOrder','Shipment'],true)){
                    $customerDirty=fn()=>\Illuminate\Support\Facades\Cache::put('customer-sales-dirty:'.$companyId,true,86400);
                    DB::transactionLevel()>0?DB::afterCommit($customerDirty):$customerDirty();
                }
                if(in_array($entityType,['FinancialAccount','FinancialAccountTransaction','CustomerDebtTransaction','Customer','Invoice','InvoicePayment','Expense','ExpensePayment','PurchaseOrder','PurchaseOrderPayment','Shipment'],true)){
                    $dirty=fn()=>\Illuminate\Support\Facades\Cache::put('finance-dirty:'.$companyId,true,86400);
                    DB::transactionLevel()>0?DB::afterCommit($dirty):$dirty();
                }
                $invalidate = static function () use ($entity,$companyId) {
                    try {
                    if(!in_array(class_basename($entity),['Product','ProductSupplier','StockMovement','DailySale','Invoice','PurchaseOrder','GoodsReceipt','StockTransfer','SalesOrder'],true))return;
                    $ids=$entity instanceof \App\Models\Product?[$entity->id]:(!empty($entity->product_id)?[$entity->product_id]:[]);
                    if(!$ids&&in_array(class_basename($entity),['DailySale','Invoice','PurchaseOrder','GoodsReceipt','StockTransfer'],true))$ids=$entity->items()->pluck('product_id')->all();
                    if($ids)InventoryPlanningService::invalidate($companyId,$ids);
                    } catch(Throwable $e) { Log::warning('Planning invalidation deferred to scheduled refresh.',['company_id'=>$companyId]); }
                };
                DB::transactionLevel()>0?DB::afterCommit($invalidate):$invalidate();
                $invalidateDecisions=static function()use($entity,$companyId,$eventType){
                    try{
                        $type=class_basename($entity);
                        if(!in_array($type,['Product','ProductSupplier','StockMovement','DailySale','Invoice','PurchaseOrder','GoodsReceipt','StockTransfer','SalesOrder','Shipment','QualityInspection','SupplierClaim','InventoryRecommendation','AnalyticsPrediction','SupplierDeliveryRisk','SupplierLeadModel','InventoryReservation'],true))return;
                        $ids=$entity instanceof \App\Models\Product?[$entity->id]:(!empty($entity->product_id)?[$entity->product_id]:[]);
                        if(!$ids&&in_array($type,['DailySale','Invoice','PurchaseOrder','GoodsReceipt','StockTransfer','SalesOrder'],true))$ids=$entity->items()->pluck('product_id')->all();
                        if($type==='AnalyticsPrediction'&&$entity->entity_type==='product')$ids=[$entity->entity_id];
                        if($type==='Shipment'){$po=$entity->purchaseOrders()->pluck('purchase_orders.id')->all();if($entity->purchase_order_id)$po[]=$entity->purchase_order_id;$ids=\App\Models\PurchaseOrderItem::whereIn('purchase_order_id',$po)->pluck('product_id')->all();}
                        if(!$ids&&!empty($entity->supplier_id))$ids=\App\Models\ProductSupplier::where('supplier_id',$entity->supplier_id)->limit(200)->pluck('product_id')->all();
                        EnterpriseDecisionService::invalidate($companyId,$ids);
                        if(!str_starts_with($eventType,'shipment.intelligence.'))ShipmentIntelligenceService::invalidateProducts($companyId,$ids);
                    }catch(Throwable $e){Log::warning('Decision invalidation deferred to scheduled reconciliation.',['company_id'=>$companyId]);}
                };
                DB::transactionLevel()>0?DB::afterCommit($invalidateDecisions):$invalidateDecisions();
                // Independent, after-commit orchestration. Scheduler recovers
                // durable events if queue dispatch is temporarily unavailable.
                $automate = static function () use ($event) {
                    try { \App\Jobs\RunBusinessAutomations::dispatch($event->id); }
                    catch (Throwable $e) { Log::warning('Automation dispatch deferred to scheduler.', ['event_id'=>$event->id]); }
                };
                DB::transactionLevel() > 0 ? DB::afterCommit($automate) : $automate();
                $dispatch = static fn () => DeliverBusinessEventWebhooks::dispatch($event->id);
                DB::transactionLevel() > 0 ? DB::afterCommit($dispatch) : $dispatch();
            }

            return $event;
        } catch (Throwable $exception) {
            // The event ledger is a secondary reaction. A logging/integration
            // failure must never invalidate the completed domain operation.
            Log::warning('Business event recording failed.', [
                'event_type' => $eventType,
                'entity_type' => class_basename($entity),
                'entity_id' => $entity->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function sanitize(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (preg_match('/secret|password|token|credential|api[_-]?key/i', (string) $key)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }
}
