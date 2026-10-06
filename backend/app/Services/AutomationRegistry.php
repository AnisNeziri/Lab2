<?php
namespace App\Services;

use App\Models\{BusinessEvent, Product, SalesOrder, OrderIntake, Customer, PurchaseOrder, QualityInspection, SupplierClaim, Shipment, Document, AccountingException, IntegrationProvider, OperationalTask};
use Illuminate\Support\Facades\Auth;

/** Explicit allowlists: adding a provider requires code review, never user code. */
class AutomationRegistry
{
    public const SCHEDULED_TRIGGERS=['inventory.low_stock','inventory.stockout','customer.payment_overdue','customer.credit_warning','purchase_order.overdue','shipment.delayed','shipment.eta_changed','document.expiring','integration.failed','task.overdue'];
    public const SOURCES = [
        'SupplyOptimizationPlan'=>[\App\Models\SupplyOptimizationPlan::class,'analytics.finance','/supply-optimizer?plan='],
        'DecisionLearningRecord'=>[\App\Models\DecisionLearningRecord::class,'analytics.view','/decision-learning?record='],
        'CustomerSalesSnapshot'=>[\App\Models\CustomerSalesSnapshot::class,'analytics.view','/customer-sales-intelligence?snapshot='],
        'FinancialIntelligenceSnapshot'=>[\App\Models\FinancialIntelligenceSnapshot::class,'analytics.finance','/financial-intelligence?snapshot='],
        'EnterpriseDecision'=>[\App\Models\EnterpriseDecision::class,'analytics.view','/inventory-intelligence?view=decisions&decision='],
        'Supplier'=>[\App\Models\Supplier::class,'supplier_performance.view','/suppliers?supplier='],
        'InventoryForecastModel'=>[\App\Models\InventoryForecastModel::class,'analytics.view','/inventory-intelligence?model='],
        'InventoryIntelligenceAlert'=>[\App\Models\InventoryIntelligenceAlert::class,'analytics.view','/inventory-intelligence?alert='],
        'InventoryRecommendation'=>[\App\Models\InventoryRecommendation::class,'analytics.view','/inventory-intelligence?recommendation='],
        'AnalyticsIssue'=>[\App\Models\AnalyticsIssue::class,'analytics.data_quality','/analytics?tab=data_quality&issue='],
        'Product'=>[Product::class,'products.manage','/products?product='],
        'SalesOrder'=>[SalesOrder::class,'fulfillment.view','/fulfillment?order='],
        'OrderIntake'=>[OrderIntake::class,'fulfillment.view','/order-hub?intake='],
        'Customer'=>[Customer::class,'debts.view','/customer-debts?customer='],
        'PurchaseOrder'=>[PurchaseOrder::class,'purchase_orders.view','/purchase-orders?po='],
        'QualityInspection'=>[QualityInspection::class,'quality.view','/quality?inspection='],
        'SupplierClaim'=>[SupplierClaim::class,'quality.claims.view','/quality?claim='],
        'Shipment'=>[Shipment::class,'shipments.view','/shipments/my-shipments?shipment='],
        'Document'=>[Document::class,'documents.view','/documents?document='],
        'AccountingException'=>[AccountingException::class,'accounting.integrity.view','/accounting?tab=integrity&exception='],
        'IntegrationProvider'=>[IntegrationProvider::class,'integrations.manage','/system-integrity?provider='],
        'OperationalTask'=>[OperationalTask::class,'tasks.view','/action-center?task='],
    ];

    public function triggers(): array
    {
        $definitions = [
            ...array_combine(['optimizer.plan.generated','optimizer.plan.stale','optimizer.plan.infeasible','optimizer.critical_constraint','optimizer.plan.approved','optimizer.plan.executed'],array_map(fn($en,$sq)=>[$en,$sq,'SupplyOptimizationPlan',['status','plan_id']],['Supply plan generated','Supply plan stale','Supply plan infeasible','Critical supply constraint','Supply plan reviewed','Supply execution observed'],['Plani i furnizimit u gjenerua','Plani i furnizimit është i vjetruar','Plani i furnizimit është i parealizueshëm','Kufizim kritik i furnizimit','Plani i furnizimit u rishikua','U vëzhgua realizimi i furnizimit'])),
            ...array_combine(array_map(fn($e)=>'intelligence.learning.'.$e,['outcome_completed','performance_degraded','challenger_ready','policy_review_suggested','champion_promoted','champion_rolled_back']),array_map(fn($en,$sq)=>[$en,$sq,'DecisionLearningRecord',['state','samples']],['Decision outcome completed','Decision performance review','Policy challenger ready','Policy review suggested','Champion policy promoted','Champion policy rolled back'],['Rezultati i vendimit përfundoi','Rishikim i performancës','Kandidati i politikës është gati','Sugjerohet rishikim i politikës','Politika aktive u promovua','Politika aktive u rikthye'])),
            ...array_combine(array_map(fn($e)=>'customer.intelligence.'.$e,['activity_changed','reorder_window','sales_opportunity','concentration_changed','high_value_inactivity']),array_map(fn($en,$sq)=>[$en,$sq,'CustomerSalesSnapshot',['customer_id','product_id','state','confidence','change_points','issue_count']],['Customer activity changed','Likely reorder review','Related product review','Sales concentration changed','High-value customer inactivity'],['Aktiviteti i klientit ndryshoi','Rishikim i riporositjes','Rishikim i produktit të lidhur','Përqendrimi i shitjeve ndryshoi','Joaktivitet i klientit me vlerë të lartë'])),
            ...array_combine(array_map(fn($e)=>'finance.intelligence.'.$e,['cash_pressure_detected','receivable_risk_changed','large_commitment_upcoming','forecast_materially_changed','data_quality_issue']),array_map(fn($en,$sq)=>[$en,$sq,'FinancialIntelligenceSnapshot',['confidence','periods','risk','customer_id','commitments','currency','issue_count']],['Projected cash pressure','Receivable risk changed','Large commitment upcoming','Financial forecast materially changed','Financial data quality issue'],['Presion i parashikuar i parasë','Rreziku i arkëtimit ndryshoi','Detyrim i madh në afërsi','Parashikimi financiar ndryshoi ndjeshëm','Problem me cilësinë e të dhënave financiare'])),
            ...array_combine(array_map(fn($e)=>'shipment.intelligence.'.$e,['risk_changed','eta_materially_changed','inventory_exposure','tracking_stale','arriving_soon']),array_map(fn($en,$sq)=>[$en,$sq,'Shipment',['risk','confidence','predicted_eta','exposed_products','critical_products','no_alternative_incoming','primary_reason']],['Shipment risk changed','Shipment ETA materially changed','Shipment inventory exposure','Shipment tracking stale','Shipment arriving soon'],['Rreziku i dërgesës ndryshoi','ETA e dërgesës ndryshoi ndjeshëm','Inventar i ekspozuar nga dërgesa','Gjurmimi i dërgesës është i vjetruar','Dërgesa mbërrin së shpejti'])),
            'intelligence.decision.created'=>['Enterprise decision created','Vendimi i biznesit u krijua','EnterpriseDecision',['severity','decision_type','confidence','quantity','stockout_date']],
            'intelligence.decision.materially_changed'=>['Enterprise decision changed','Vendimi i biznesit ndryshoi','EnterpriseDecision',['severity','decision_type','confidence','quantity','stockout_date']],
            'intelligence.decision.critical'=>['Critical enterprise decision','Vendim kritik i biznesit','EnterpriseDecision',['severity','decision_type','confidence','quantity','stockout_date']],
            'intelligence.decision.accepted'=>['Enterprise decision accepted','Vendimi i biznesit u pranua','EnterpriseDecision',['severity','decision_type','confidence','quantity','stockout_date']],
            'intelligence.decision.dismissed'=>['Enterprise decision dismissed','Vendimi i biznesit u refuzua','EnterpriseDecision',['severity','decision_type','confidence','quantity','stockout_date']],
            'intelligence.decision.resolved'=>['Enterprise decision resolved','Vendimi i biznesit u zgjidh','EnterpriseDecision',['severity','decision_type','confidence','quantity','stockout_date']],
            'intelligence.decision.superseded'=>['Enterprise decision superseded','Vendimi i biznesit u zëvendësua','EnterpriseDecision',['severity','decision_type','confidence','quantity','stockout_date']],
            'inventory.optimization.reorder_required'=>['Replenishment action required','Kërkohet veprim rifurnizimi','InventoryRecommendation',['risk','quantity','stockout_date','state']],
            'inventory.optimization.stockout_risk_changed'=>['Stockout risk changed','Rreziku i mungesës ndryshoi','InventoryRecommendation',['risk','quantity','stockout_date','state']],
            'inventory.optimization.excess_detected'=>['Excess inventory detected','U zbulua inventar i tepërt','InventoryRecommendation',['quantity','state']],
            'inventory.optimization.transfer_opportunity'=>['Warehouse transfer opportunity','Mundësi transferimi ndërmjet depove','InventoryRecommendation',['quantity','state','transfer_options']],
            'inventory.optimization.recommendation_changed'=>['Inventory recommendation changed','Rekomandimi i inventarit ndryshoi','InventoryRecommendation',['risk','quantity','stockout_date','state','supplier_id']],
            'inventory_planning.stockout'=>['Projected inventory shortage','Mungesë e parashikuar e inventarit','InventoryRecommendation',['risk','quantity','stockout_date']],
            'inventory_planning.urgent_reorder'=>['Urgent replenishment review','Rishikim urgjent i rifurnizimit','InventoryRecommendation',['risk','quantity','stockout_date']],
            'inventory_planning.incoming_delay'=>['Incoming delay affects inventory','Vonesa e furnizimit ndikon në inventar','InventoryRecommendation',['risk','quantity','stockout_date']],
            'inventory_planning.review'=>['Purchasing plan review','Rishikim i planit të blerjeve','InventoryRecommendation',['risk','quantity','stockout_date']],
            'supplier_delivery.high_risk'=>['Incoming delivery at risk','Pranimi i mallrave në rrezik','PurchaseOrder',['risk','causes','original_promise']],
            'supplier_model.review'=>['Supplier lead-time candidate ready','Kandidati i afatit të furnitorit është gati','Supplier',['version','samples']],
            'model_candidate_ready'=>['Forecast candidate ready for review','Kandidati i parashikimit është gati për rishikim','InventoryForecastModel',['horizon','algorithm','coverage']],
            'forecast_accuracy_degraded'=>['Forecast performance warning','Paralajmërim i performancës së parashikimit','InventoryIntelligenceAlert',['horizon','code','evidence']],
            'intelligence_data_quality_problem'=>['Forecast observation quality problem','Problem me cilësinë e vëzhgimeve','InventoryIntelligenceAlert',['horizon','code','evidence']],
            'inventory_forecast.updated'=>['Inventory forecast updated','Parashikimi i stokut u përditësua','InventoryRecommendation',['horizon','predicted_demand','risk']],
            'inventory_stockout_risk.detected'=>['Forecast stockout risk','Rrezik i parashikuar i mungesës së stokut','InventoryRecommendation',['risk','recommended_quantity','stockout_date']],
            'analytics.data_quality_problem'=>['Analytics data-quality problem','Problem i cilësisë së të dhënave','AnalyticsIssue',['severity','code']],
            'inventory.low_stock'=>['Low stock','Stok i ulët','Product',['available','minimum','shortage']],
            'inventory.stockout'=>['Stockout','Stok i mbaruar','Product',['available','minimum','shortage']],
            'sales_order.confirmed'=>['Order confirmed','Porosia u konfirmua','SalesOrder',['total_amount','status','customer_id']],
            'order.payment_received'=>['Order payment received','Pagesa e porosisë u pranua','SalesOrder',['total_amount','status','customer_id','amount']],
            'packing.completed'=>['Order packed','Porosia u paketua','SalesOrder',['total_amount','status']],
            'dispatch.departed'=>['Order dispatched','Porosia u dërgua','SalesOrder',['total_amount','status']],
            'delivery.failed'=>['Delivery failed','Dorëzimi dështoi','SalesOrder',['status']],
            'customer_return.created'=>['Return requested','Kthimi u kërkua','SalesOrder',['status']],
            'customer.payment_overdue'=>['Customer overdue balance','Borxhi i vonuar i klientit','Customer',['overdue','total_exposure','credit_limit']],
            'customer.credit_warning'=>['Customer credit warning','Paralajmërim kredie','Customer',['overdue','total_exposure','credit_limit','utilization_percent']],
            'purchase_order.overdue'=>['Purchase order overdue','Porosia e blerjes është vonuar','PurchaseOrder',['status','expected_at','total_amount']],
            'purchase_order.received'=>['Purchase order received','Porosia e blerjes u pranua','PurchaseOrder',['status','total_amount']],
            'quality.inspection_finalized'=>['Inspection finalized','Inspektimi përfundoi','QualityInspection',['status']],
            'supplier.claim_created'=>['Supplier claim created','Ankesa ndaj furnitorit u krijua','SupplierClaim',['status','affected_value']],
            'shipment.delayed'=>['Shipment delayed','Dërgesa është vonuar','Shipment',['status','eta','delay_days']],
            'shipment.eta_changed'=>['Shipment ETA changed','Ndryshoi data e mbërritjes','Shipment',['status','eta','delay_days']],
            'document.expiring'=>['Document approaching expiry','Dokumenti afër skadimit','Document',['status','expiry_date']],
            'document.review_requested'=>['Document review requested','Kërkohet rishikim dokumenti','Document',['status']],
            'accounting.exception_detected'=>['Accounting exception','Përjashtim kontabël','AccountingException',['severity','type']],
            'integration.failed'=>['Integration degraded','Integrimi ka probleme','IntegrationProvider',['health_state']],
            'task.overdue'=>['Task overdue','Detyra është vonuar','OperationalTask',['status','priority','due_at']],
        ];
        return collect($definitions)->map(fn($d,$key)=>['key'=>$key,'label'=>$d[0],'label_sq'=>$d[1],'source'=>$d[2],'permission'=>self::SOURCES[$d[2]][1],'scheduled'=>in_array($key,self::SCHEDULED_TRIGGERS,true),'fields'=>array_merge(['reference'], $d[3])])->all();
    }

    public function actions(): array
    {
        return [
            'create_task'=>['label'=>'Create operational task','label_sq'=>'Krijo detyrë operative','risk'=>'LOW_RISK','permission'=>'tasks.create'],
            'notify'=>['label'=>'Send notification','label_sq'=>'Dërgo njoftim','risk'=>'LOW_RISK','permission'=>'tasks.create'],
            'request_document_review'=>['label'=>'Request document approval','label_sq'=>'Kërko miratim dokumenti','risk'=>'APPROVAL_REQUIRED','permission'=>'documents.review','source'=>'Document'],
            'purchase_request_draft'=>['label'=>'Prepare purchase request draft','label_sq'=>'Përgatit draft kërkese për blerje','risk'=>'CONTROLLED','permission'=>'procurement.manage','source'=>'Product'],
        ];
    }

    public function source(string $type, int $id): mixed
    {
        $definition=self::SOURCES[$type] ?? null;
        if (!$definition) return null;
        if (in_array($type,['SupplyOptimizationPlan','FinancialIntelligenceSnapshot','CustomerSalesSnapshot']) && !$this->canAccessSource($type)) return null;
        $source=$definition[0]::query()->where('company_id',Auth::user()->company_id)->find($id);
        if($type==='DecisionLearningRecord'&&$source&&!in_array($source->domain,app(DecisionLearningService::class)->allowedDomains()))return null;
        if ($source instanceof Document) return app(DocumentService::class)->visibleQuery()->find($id);
        return $source;
    }

    public function url(string $type, int $id): ?string
    {
        $d=self::SOURCES[$type] ?? null;
        if($type==='DecisionLearningRecord'){
            if(!$d||!app(PermissionService::class)->roleHasPermission(Auth::user()->role,$d[1]))return null;
            $r=$this->source($type,$id);if(!$r)return null;
            $recommendation=$r->kind==='recommendation'?$r->id:($r->payload['recommendation_id']??null);
            if($recommendation)return '/decision-learning?tab=outcomes&record='.$recommendation;
            $tab=match($r->kind){'experiment','policy_change'=>'challengers','suggestion'=>'suggestions','prediction','prediction_outcome'=>'performance',default=>'overview'};
            return '/decision-learning?tab='.$tab;
        }
        if (in_array($type,['SupplyOptimizationPlan','FinancialIntelligenceSnapshot','CustomerSalesSnapshot']) && !$this->canAccessSource($type)) return null;
        if($type==='InventoryRecommendation'&&$d&&app(PermissionService::class)->roleHasPermission(Auth::user()->role,$d[1])){
            $r=\App\Models\InventoryRecommendation::find($id);if(!$r)return null;
            if($r->planning_key)return '/inventory-intelligence?view=planning&product='.$r->product_id.'&recommendation='.$r->id.($r->warehouse_id?'&warehouse_id='.$r->warehouse_id:'');
        }
        return $d && app(PermissionService::class)->roleHasPermission(Auth::user()->role,$d[1]) ? $d[2].$id : null;
    }

    public function canAccessSource(string $type, ?string $role=null): bool
    {
        $d=self::SOURCES[$type] ?? null;
        if (!$d || !($role ??= Auth::user()?->role)) return false;
        $required=$type==='SupplyOptimizationPlan'?SupplyOptimizerService::PERMISSIONS:($type==='CustomerSalesSnapshot'?CustomerSalesIntelligenceService::PERMISSIONS:($type==='FinancialIntelligenceSnapshot'?['analytics.finance','finance.view','financial_accounts.view']:[$d[1]]));
        foreach($required as $permission) if(!app(PermissionService::class)->roleHasPermission($role,$permission)) return false;
        return true;
    }

    public function context(BusinessEvent $event): array
    {
        $trigger=$this->triggers()[$event->event_type] ?? null;
        if (!$trigger || $event->entity_type!==$trigger['source']) return [];
        $source=$this->source($event->entity_type,(int)$event->entity_id);
        if (!$source) return [];
        $context=['reference'=>$event->reference ?: $source->name ?: '#'.$source->id];
        foreach ($trigger['fields'] as $field) {
            if ($field==='reference') continue;
            // Immutable event metadata wins over a current projection.
            if (array_key_exists($field,$event->metadata ?? [])) $context[$field]=$event->metadata[$field];
            elseif (array_key_exists($field,$source->getAttributes())) $context[$field]=$source->getRawOriginal($field);
            if (array_key_exists($field,$event->metadata['previous'] ?? [])) $context['previous.'.$field]=$event->metadata['previous'][$field];
        }
        if ($source instanceof Product) {
            $available=app(InventorySnapshotService::class)->forProduct($source)['available'];
            $context+=['available'=>$available,'minimum'=>$source->min_quantity,'shortage'=>max(0,(float)$source->min_quantity-(float)$available)];
        }
        if ($source instanceof Customer) $context+=array_intersect_key(app(CustomerCreditService::class)->exposure($source),array_flip($trigger['fields']));
        if ($source instanceof Shipment) $context+=['delay_days'=>$source->eta ? max(0,(int)$source->eta->diffInDays(now(),false)):0];
        return $context;
    }

    public function templates(): array
    {
        $conditions=[
            'inventory.low_stock'=>['field'=>'shortage','operator'=>'gt','value'=>0],
            'inventory.stockout'=>['field'=>'available','operator'=>'lte','value'=>0],
            'customer.payment_overdue'=>['field'=>'overdue','operator'=>'gt','value'=>0],
            'customer.credit_warning'=>['field'=>'utilization_percent','operator'=>'gte','value'=>90],
            'quality.inspection_finalized'=>['field'=>'status','operator'=>'in','value'=>['failed','rejected']],
        ];
        return collect($this->triggers())->map(fn($t)=>[
            'name'=>$t['label'],'name_sq'=>$t['label_sq'],'trigger'=>$t['key'],'enabled'=>false,
            'description'=>'Review the source record and take the appropriate action.',
            'conditions'=>['all'=>isset($conditions[$t['key']])?[$conditions[$t['key']]]:[]],
            'actions'=>[['type'=>'create_task','title'=>$t['label'],'due_days'=>1]],'priority'=>'normal',
        ])->values()->all();
    }
}
