<?php
namespace App\Services;

/** Language-independent values are copied from authoritative structured evidence. */
final class AssistantComposer
{
    public const URLS=[
        'get_decision_learning_summary'=>'/decision-learning','get_recommendation_performance'=>'/decision-learning','get_decision_outcome'=>'/decision-learning','compare_policy_versions'=>'/decision-learning','get_challenger_status'=>'/decision-learning','get_override_patterns'=>'/decision-learning','get_policy_suggestions'=>'/decision-learning',
        'simulate_customer_payment_delay'=>'/financial-intelligence','simulate_shipment_delay'=>'/shipments/my-shipments','get_customer_supply_capacity'=>'/inventory-intelligence?view=planning',
        'get_open_decisions'=>'/inventory-intelligence?view=decisions','get_critical_decisions'=>'/inventory-intelligence?view=decisions','get_replenishment_decisions'=>'/inventory-intelligence?view=decisions','get_supplier_decisions'=>'/inventory-intelligence?view=decisions','get_decision_changes'=>'/inventory-intelligence?view=decisions',
        'get_products_needing_replenishment'=>'/inventory-intelligence?view=planning','get_inventory_intelligence_recommendations'=>'/inventory-intelligence',
        'get_at_risk_shipments'=>'/shipments/my-shipments','get_shipment_intelligence'=>'/shipments/my-shipments',
        'get_cash_forecast'=>'/financial-intelligence','get_receivable_intelligence'=>'/financial-intelligence','get_financial_pressure_periods'=>'/financial-intelligence','simulate_purchase_cash_impact'=>'/financial-intelligence',
        'get_at_risk_customers'=>'/customer-sales-intelligence','get_sales_opportunities'=>'/customer-sales-intelligence','get_customer_intelligence'=>'/customer-sales-intelligence',
        'get_pending_approvals'=>'/action-center','get_action_center'=>'/action-center','get_task'=>'/action-center','get_sales_analytics'=>'/analytics?area=sales',
        'get_product_availability'=>'/products','get_stock_movements'=>'/stock','get_demand_forecast'=>'/inventory-intelligence','get_inventory_plan'=>'/inventory-intelligence?view=planning','get_supplier_options'=>'/inventory-intelligence?view=planning','simulate_inventory_scenario'=>'/inventory-intelligence?view=planning','simulate_decision_change'=>'/inventory-intelligence?view=decisions','get_decision'=>'/inventory-intelligence?view=decisions','compare_decision_alternatives'=>'/inventory-intelligence?view=decisions','get_product_decision'=>'/inventory-intelligence?view=decisions','get_customer'=>'/customer-debts','get_supplier'=>'/suppliers','get_supplier_performance'=>'/suppliers','get_product_incoming_risk'=>'/shipments/my-shipments',
    ];

    public function compose(array $results,bool $sq):array
    {
        $cards=[];$sources=[];$limits=[];
        foreach($results as $result) {
            $name=$result['tool'];$data=$result['data'];$url=self::URLS[$name]??'/dashboard';
            if(in_array($name,AimsToolRegistry::OPTIMIZER_TOOLS))$url='/supply-optimizer'.(isset($data['id'])?'?plan='.$data['id']:(isset($data['plan_id'])?'?plan='.$data['plan_id']:''));
            $asof=$data['evidence_cutoff']??$data['evidence_cutoff_at']??$data['as_of']??$data['generated_at']??null;
            $sources[]=['tool'=>$name,'url'=>$url,'as_of'=>$asof,'stale'=>$data['stale']??null,'read_at'=>now()->toIso8601String()];
            if(isset($data['qualification']))$limits[]=$data['qualification'];
            if(($data['state']??'')==='not_calculated'){$limits[]=$sq?'Nuk ka ende dëshmi të llogaritura. Hap burimin për llogaritje.':'No calculated evidence exists yet. Open the source to calculate it.';continue;}
            $rows=$data['rows']??$data['shipments']??(isset($data['data'])&&array_is_list($data['data'])?$data['data']:null);
            if($name==='get_decision_learning_summary')$rows=[$data];
            if(in_array($name,AimsToolRegistry::OPTIMIZER_TOOLS)){
                if(isset($data['result']['plans']))$rows=array_map(fn($p)=>['name'=>match($p['key']){'balanced'=>$sq?'I balancuar':'Balanced','service_first'=>$sq?'Shërbimi së pari':'Service-first',default=>$sq?'Angazhim më i ulët':'Lower commitment'},'status'=>$p['status'],'confidence'=>$p['summary']['confidence']??null,'summary'=>$p['summary']??[],'qualification'=>$p['qualification']??null,'url'=>$url],$data['result']['plans']);
                elseif(isset($data['alternatives']))$rows=collect($data['alternatives'])->flatMap(fn($a)=>array_map(fn($d)=>$d+['name'=>data_get($d,'product.name','Product').' · '.match($a['key']){'balanced'=>$sq?'I balancuar':'Balanced','service_first'=>$sq?'Shërbimi së pari':'Service-first',default=>$sq?'Angazhim më i ulët':'Lower commitment'},'alternative'=>$a['key']],$a['decisions']??[]))->all();
                else $rows=isset($data['id'])?[$data+['name'=>$sq?'Plani i furnizimit #'.$data['id']:'Supply plan #'.$data['id']]]:($data['rows']??[$data]);
            }
            if(in_array($name,['get_challenger_status','get_policy_suggestions'])&&!$rows)$rows=[['state'=>'COLLECTING_DECISION_OUTCOMES','name'=>$sq?'Ende pa dëshmi të mjaftueshme':'Not enough evidence yet']];
            if($name==='get_cash_forecast')$rows=collect($data['forecast']['currencies']??[])->map(fn($r,$currency)=>$r+['currency'=>$currency])->values()->all();
            if(in_array($name,['simulate_purchase_cash_impact','simulate_customer_payment_delay'])){
                $rows=[];foreach(['base','scenario'] as $state)foreach($data[$state]['currencies']??[] as $currency=>$values)$rows[]=$values+['name'=>($state==='base'?($sq?'Baza':'Baseline'):($sq?'Skenari':'Scenario')).' · '.$currency,'currency'=>$currency];
            }
            if($name==='get_receivable_intelligence')$rows=collect($data['customers']??[])->sortByDesc(fn($r)=> (float)($r['debt']??$r['remaining']??$r['amount']??0))->values()->all();
            if($name==='get_at_risk_customers'||$name==='get_sales_opportunities')$rows=$data['data']??[];
            if($name==='get_customer_intelligence')$rows=isset($data['profile'])?[$data['profile']]:[];
            if($name==='get_supplier_options')$rows=$data['suppliers']??[];
            if($name==='simulate_inventory_scenario')$rows=$data['scenarios']??[];
            if($name==='simulate_decision_change')$rows=$data['comparison']['scenarios']??[];
            if($name==='compare_decision_alternatives')$rows=$data['alternatives']??[];
            if($name==='get_pending_approvals')$rows=$data;
            if($rows===null)$rows=[$data];
            $rows=collect($rows)->take(8)->all();
            foreach($rows as $index=>$row) {
                if(!is_array($row))continue;
                $title=$row['name']??$row['customer_name']??$row['product_name']??$row['title']??data_get($row,'evidence.product.name')??data_get($row,'product.name')??$row['reference']??$row['supplier_name']??$row['currency']??$name;
                if(!is_scalar($title))$title=$name;
                $rowurl=$row['url']??$url;
                if($name==='get_product_availability')$rowurl='/products?product='.($row['product']['id']??'');
                if($name==='get_customer_intelligence'||$name==='get_at_risk_customers')$rowurl='/customer-sales-intelligence?customer='.($row['id']??'');
                if($name==='get_supplier_options')$rowurl='/suppliers?supplier='.($row['supplier_id']??'');
                $cards[]=['title'=>(string)$title,'tool'=>$name,'url'=>$rowurl,'status'=>$row['severity']??$row['risk']??data_get($row,'recommendation.risk')??$row['state']??$row['status']??null,'confidence'=>$row['confidence']??data_get($row,'cadence.confidence'),'stale'=>$row['stale']??$data['stale']??null,'as_of'=>$row['evidence_cutoff_at']??$asof,'metrics'=>$this->metrics($row,$sq),'evidence'=>$this->compact($row),'entity'=>$this->entity($name,$row)];
            }
        }
        return ['cards'=>$cards,'sources'=>$sources,'limitations'=>array_values(array_unique($limits))];
    }

    private function entity(string $tool,array $row):?array
    {
        if(in_array($tool,['get_open_decisions','get_critical_decisions','get_replenishment_decisions','get_supplier_decisions','get_decision']))return isset($row['id'])?['type'=>'decision','id'=>$row['id']]:null;
        if(in_array($tool,['get_at_risk_customers','get_customer_intelligence']))return isset($row['id'])?['type'=>'customer','id'=>$row['id']]:null;
        if($tool==='get_products_needing_replenishment')return isset($row['product']['id'])?['type'=>'product','id'=>$row['product']['id']]:null;
        if($tool==='get_at_risk_shipments')return isset($row['shipment_id'])?['type'=>'shipment','id'=>$row['shipment_id']]:null;
        return null;
    }

    private function metrics(array $row,bool $sq):array
    {
        $labels=[
            'summary.commitment'=>['New purchasing commitment','Angazhimi i ri i blerjeve'],'summary.products_analyzed'=>['Scopes analyzed','Shtrirjet e analizuara'],'summary.purchase_lines'=>['Purchase lines','Rreshta blerjeje'],'summary.transfer_lines'=>['Transfer lines','Rreshta transferimi'],'summary.unresolved_scopes'=>['Unresolved scopes','Shtrirje të pazgjidhura'],'summary.stockout_days'=>['Projected shortage days','Ditë mungese të parashikuara'],'purchase_quantity'=>['Purchase quantity','Sasia e blerjes'],'transfer_quantity'=>['Transfer quantity','Sasia e transferimit'],'projected_need'=>['Projected need','Nevoja e parashikuar'],'unmet_target'=>['Preferred-target gap','Mungesa ndaj objektivit'],
            'summary.evaluated'=>['Completed observed decision windows','Periudha vendimesh të vëzhguara'],'summary.waiting'=>['Waiting for genuine outcomes','Në pritje të rezultateve reale'],'summary.prediction_evaluations'=>['Prediction evaluations (separate)','Vlerësime parashikimi (veçmas)'],'data_sufficiency.required'=>['Minimum independent samples for review','Mostrat minimale për rishikim'],'production.version'=>['Production champion','Politika aktive'],'median_quantity_change_percent'=>['Median human quantity change %','Ndryshimi median i sasisë %'],'modified_samples'=>['Completed modified windows','Periudha të ndryshuara të përfunduara'],'independent_samples'=>['Independent observations','Vëzhgime të pavarura'],'comparison.independent_samples'=>['Independent paired scenarios','Skenarë të çiftuar të pavarur'],'comparison.minimum_samples'=>['Minimum samples','Mostrat minimale'],'comparison.eligible_for_human_review'=>['Eligible for explicit human review','Lejohet rishikim i shprehur'],'data.response.recommended_quantity'=>['Recommended quantity','Sasia e rekomanduar'],'data.response.chosen_quantity'=>['Chosen quantity','Sasia e zgjedhur'],'data.scorecard.stockout_days'=>['Observed stockout days','Ditët e mungesës së vëzhguar'],'data.scorecard.excess_mean_quantity'=>['Mean observed excess','Teprica mesatare e vëzhguar'],
            'product.quantity'=>['On hand','Në stok'],'product.available_quantity'=>['Available to sell','Për shitje'],'plan.stock.available_to_promise'=>['Available to promise','Në dispozicion'],'plan.base_quantity'=>['Recommended quantity','Sasia e rekomanduar'],'plan.stockout_date'=>['Stockout date','Data e mungesës'],'plan.expected_arrival'=>['Expected arrival','Mbërritja e pritshme'],
            'recommended.base_quantity'=>['Recommended quantity','Sasia e rekomanduar'],'recommended.supplier_name'=>['Supplier','Furnitori'],'recommended.base_cost'=>['Estimated cost','Kosto e vlerësuar'],'evidence.forecast.stockout_date'=>['Stockout date','Data e mungesës'],'evidence.required_quantity'=>['Required quantity','Sasia e nevojshme'],
            'recommendation.explanation.stockout_date'=>['Projected stockout date','Data e mungesës së parashikuar'],'recommendation.explanation.base_quantity'=>['Recommended quantity','Sasia e rekomanduar'],'prediction.generated_at'=>['Forecast generated','Parashikimi u gjenerua'],'prediction.valid_until'=>['Forecast expiry','Skadimi i parashikimit'],
            'base_quantity'=>['Quantity','Sasia'],'quantity'=>['Quantity','Sasia'],'unit'=>['Unit','Njësia'],'currency'=>['Currency','Monedha'],'base_cost'=>['Estimated cost','Kosto e vlerësuar'],'supplier_name'=>['Supplier','Furnitori'],'expected_arrival'=>['Expected arrival','Mbërritja e pritshme'],'usual_lead_time_days'=>['Lead time days','Afati ditë'],'minimum_order_quantity'=>['Minimum quantity','Sasia minimale'],'pack_size'=>['Pack multiple','Shumëfishi i paketës'],'stockout_date'=>['Stockout date','Data e mungesës'],'scenario_stockout_date'=>['Scenario stockout','Mungesa në skenar'],'available_to_promise'=>['Available','Në dispozicion'],
            'can_fulfil_now'=>['Can fulfil now (physical stock)','Mund të plotësohet tani (stok fizik)'],'customer'=>['Customer','Klienti'],'shifted_receipts'=>['Dated receipts shifted','Mbërritje me datë të shtyra'],'baseline_stockout'=>['Baseline stockout','Mungesa bazë'],'scenario_stockout'=>['Scenario stockout','Mungesa në skenar'],'baseline_stockout_days'=>['Baseline shortage days','Ditët e mungesës bazë'],'scenario_stockout_days'=>['Scenario shortage days','Ditët e mungesës në skenar'],
            'current_debt'=>['Debt','Borxhi'],'debt'=>['Debt','Borxhi'],'advance'=>['Advance','Parapagimi'],'remaining'=>['Outstanding','I papaguar'],'total_exposure'=>['Exposure','Ekspozimi'],'overdue'=>['Overdue','I vonuar'],'credit.current_debt'=>['Debt','Borxhi'],'credit.customer_advance'=>['Advance','Parapagimi'],'credit.total_exposure'=>['Exposure','Ekspozimi'],'credit.overdue_amount'=>['Overdue','I vonuar'],
            'financial_context.debt'=>['Debt','Borxhi'],'financial_context.advance'=>['Advance','Parapagimi'],'financial_context.exposure'=>['Exposure','Ekspozimi'],'financial_context.overdue'=>['Overdue','I vonuar'],'last_purchase'=>['Last purchase','Blerja e fundit'],'summary.revenue'=>['Recorded revenue','Të ardhurat'],'summary.sales_count'=>['Sales','Shitjet'],'cadence.expected_next'=>['Expected next order','Porosia e pritshme'],
            'opening_recorded_cash'=>['Recorded opening cash','Paratë fillestare të regjistruara'],'inflows'=>['Expected inflows','Hyrjet e pritshme'],'outflows'=>['Expected outflows','Daljet e pritshme'],'net_change'=>['Net change','Ndryshimi neto'],'expected_closing_cash'=>['Expected closing cash','Paratë e pritshme në fund'],
            'metrics.revenue'=>['Revenue','Të ardhurat'],'metrics.sales_count'=>['Sales','Shitjet'],'metrics.gross_profit'=>['Gross profit','Fitimi bruto'],'eta.expected'=>['Expected arrival','Mbërritja e pritshme'],'eta.warehouse_eta'=>['Warehouse ETA','Mbërritja në depo'],'tracking_freshness.last_position_at'=>['Last position','Pozicioni i fundit'],
        ];$out=[];
        foreach($labels as $path=>$label)if(\Illuminate\Support\Arr::has($row,$path)){$v=data_get($row,$path);if(is_scalar($v)||$v===null)$out[]=['label'=>$label[$sq?1:0],'value'=>$v,'unit'=>in_array($path,['base_quantity','quantity','plan.base_quantity','recommended.base_quantity'])?($row['unit']??data_get($row,'product.unit')??data_get($row,'evidence.product.unit')??data_get($row,'plan.unit')):null];}
        return array_slice($out,0,12);
    }

    public function compact(mixed $value,int $depth=0):mixed
    {
        if($depth>6)return null;
        if(!is_array($value))return is_string($value)?mb_substr($value,0,800):$value;
        $out=[];foreach(array_slice($value,0,array_is_list($value)?8:30,true) as $key=>$item){
            if(in_array($key,['daily','timeline','products','warehouses','policies','outcomes','evaluation','model','company_id','can_manage','can_finance','read_only','can_act']))continue;
            $out[$key]=$this->compact($item,$depth+1);
        }return $out;
    }
}
