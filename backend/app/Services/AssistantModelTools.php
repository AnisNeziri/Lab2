<?php
namespace App\Services;

use App\Contracts\IntelligenceProvider;
use Illuminate\Support\Str;

/** Extends Ask AIMS: proposals pass the existing registry, schemas and business authorities. */
final class AssistantModelTools
{
    public function reply(array $v,string $conversation,array &$context):?array
    {
        if(!config('assistant.model_tools')||!app(IntelligenceProvider::class)->available())return null;
        abort_unless(auth()->user()?->company_id,403);
        $scope=hash('sha256',json_encode(app(PermissionService::class)->forRole(auth()->user()->role)));
        if (($context['model_permission_scope'] ?? null) !== $scope) {
            unset($context['model_previous_question'], $context['model_last_calls']);
        }
        $context['model_permission_scope']=$scope;
        if(isset($v['entity']))$context['entity']=$v['entity'];
        $hint=app(AssistantPlanner::class)->plan($v['question'],$context);
        $normalized=app(AssistantLanguage::class)->normalize($v['question']);
        $customerRanking = (bool) preg_match('/\b(?:customers?|clients?|buyers?|klient\w*)\b/', $normalized)
            && (bool) preg_match('/\b(?:bought|buy|purchas\w*|sales|bler\w*|ble\w*|shitj\w*)\b/', $normalized)
            && (bool) preg_match('/\b(?:rank|most|top|largest|(?:me|ma) much|shum\w*)\b/', $normalized);
        if($customerRanking){$hint['intent']='sales';unset($context['entity']);}
        if(($context['entity']['type']??null)==='product'&&preg_match('/(?:do|should|recommend|bo|vepro).*(?:shortage|munges)|(?:shortage|munges).*(?:do|should|bo)/',$normalized))$hint['intent']='replenishment';
        $financeIntent=app(FinanceAssistantFacts::class)->intent($v['question'],$conversation);
        if($financeIntent==='finance_action_boundary')return null;
        if($financeIntent&&preg_match('/^(?:why|pse|show|shfaq|compare|krahaso)\b/',$normalized))return null;
        $workforceIntent=app(\App\Services\Workforce\WorkforceAssistantFacts::class)->intent($v['question']);
        if($workforceIntent==='boundary')return null;
        if(in_array($hint['intent']??null,['unsupported','prepare','scenario','scenario_cash'],true))return null;
        if(!isset($v['entity'])){
            if(!empty($hint['term'])&&!empty($hint['type'])){
                $resolved=app(AssistantEntityResolver::class)->resolve($hint['term'],$hint['type']);
                if($resolved['entity'])$context['entity']=$resolved['entity'];
            }
        }
        $preferred=array_flip(explode(' ','get_product_availability get_stock_movements get_warehouse_stock get_customer get_supplier get_supplier_performance get_purchase_order get_sales_order get_sales_analytics get_customer_analytics get_accounting_control_summary get_accounting_receivables get_accounting_payables get_month_close_blockers get_financial_reconciliation get_payment_allocation_review get_posted_profit_comparison get_vat_control_facts get_fiscalization_facts get_stock_commitments get_low_stock_facts get_incoming_supply get_inventory_health get_inventory_unit_totals get_open_decisions get_at_risk_shipments get_at_risk_customers get_receivable_intelligence get_products_needing_replenishment get_inventory_plan get_sales_followups get_quotation_followups get_customer_relationship get_customer_purchase_history get_task get_operating_brief'));
        $tools=collect(app(AimsToolRegistry::class)->catalog())->filter(fn($t)=>$t['read_only']&&str_starts_with($t['name'],'get_')&&$t['name']!==PurchaseComparisonService::PORTFOLIO_TOOL)->sortBy(fn($t)=>$preferred[$t['name']]??100)->take(60)->map(fn($t)=>['name'=>$t['name'],'description'=>$t['description']??'','schema'=>$t['input_schema']])->values()->all();
        // Existing deterministic routing narrows retrieval; the model still chooses calls/arguments.
        $domain=$v['entity']['type']??$hint['type']??(($hint['intent']??'record')==='record'?($context['entity']['type']??null):null);
        if($domain==='product')$tools=array_values(array_filter($tools,fn($t)=>isset($t['schema']['properties']['product_id'])||in_array($t['name'],['get_low_stock_facts','get_stock_commitments','get_warehouse_stock'],true)));
        elseif ($domain === 'sales_order') {
            $tools = array_values(array_filter($tools, fn ($tool) => in_array($tool['name'], ['get_sales_order', 'get_fulfillment_status'], true)));
        }
        elseif(($hint['intent']??null)==='sales')$tools=array_values(array_filter($tools,fn($t)=>in_array($t['name'],['get_sales_analytics','get_customer_analytics','get_product_performance','get_sales_opportunities'],true)));
        if($customerRanking)$tools=array_values(array_filter($tools,fn($t)=>$t['name']==='get_customer_analytics'));
        if(($hint['intent']??null)==='inventory_totals')$tools=array_values(array_filter($tools,fn($t)=>$t['name']==='get_inventory_unit_totals'));
        if(in_array($hint['intent']??null,['stockout','replenishment','forecast'],true))$tools=array_values(array_filter($tools,fn($t)=>in_array($t['name'],['get_inventory_plan','get_demand_forecast','get_stockout_risk','get_inventory_intelligence_recommendations','get_products_needing_replenishment','get_product_incoming_risk'],true)));
        if(($hint['intent']??null)==='brief')$tools=array_values(array_filter($tools,fn($t)=>$t['name']==='get_operating_brief'));
        if($workforceIntent){$definition=collect(app(AimsToolRegistry::class)->catalog())->firstWhere('name',$workforceIntent);$tools=$definition?[['name'=>$definition['name'],'description'=>$definition['description'],'schema'=>$definition['input_schema']]]:[];}
        $financeTool=match($financeIntent){'finance_receivables'=>'get_accounting_receivables','finance_payables'=>'get_accounting_payables','finance_reconciliation'=>'get_financial_reconciliation','finance_close'=>'get_month_close_blockers','finance_allocation'=>'get_payment_allocation_review','finance_profit'=>'get_posted_profit_comparison','finance_cash','finance_summary'=>'get_accounting_control_summary','finance_fiscal'=>'get_fiscalization_facts','finance_vat'=>'get_vat_control_facts',default=>null};
        if($financeTool&&!$domain)$tools=array_values(array_filter($tools,fn($t)=>$t['name']===$financeTool));
        if ($domain === 'product' && in_array($hint['intent'] ?? null, ['record', 'explain'], true)
            && preg_match('/\b(?:availab\w*|disponuesh\w*|free stock|e lire|sellable)\b/', $normalized)) {
            $tools = array_values(array_filter($tools, fn ($tool) => $tool['name'] === 'get_product_availability'));
        }
        // A broad health report is only evidence for a general stock/warehouse exception question.
        // A referenced product needs its own tool, and other domains must not fall into inventory health.
        $warehouseHealth = ! $domain && ($hint['intent'] ?? null) === 'inventory_health'
            || ! $domain && preg_match('/\bwarehouse\b/', $normalized)
                && preg_match('/\b(?:health|issues?|block\w*|bllok\w*|problem\w*)\b/', $normalized)
                && ! $workforceIntent && ! $financeIntent;
        if (! $warehouseHealth) {
            $tools = array_values(array_filter($tools, fn ($tool) => $tool['name'] !== 'get_inventory_health'));
        }
        if(!$tools)return null;
        $tools=array_slice($tools,0,32);
        foreach($tools as &$tool)if($tool['name']==='get_inventory_unit_totals')$tool['schema']['properties']['unit']['enum']=\App\Models\Product::query()->whereNotNull('unit')->distinct()->limit(50)->pluck('unit')->all();unset($tool);
        $p=app(IntelligenceProvider::class)->respond([['role'=>'user','content'=>json_encode(['date'=>now()->toDateString(),'language'=>$v['language']??'en','question'=>$v['question'],'context'=>array_intersect_key($context,array_flip(['model_previous_question','model_last_calls','entity','result_entities']))])]],$tools);
        if(($p['state']??null)!=='available')return null;
        if(!$p['plan']['calls'])return null;
        $companyId=auth()->user()->company_id;$actor=auth()->user()->fresh(['company']);
        abort_unless($actor&&(int)$actor->company_id===(int)$companyId,403);
        \Illuminate\Support\Facades\Auth::setUser($actor);
        if(hash('sha256',json_encode(app(PermissionService::class)->forRole($actor->role)))!==$scope){$context=[];return null;}
        // Inference time is separate from the existing business-tool execution budget.
        $runner=app(AssistantToolRunner::class);
        $id=(string)Str::uuid();$results=[];$calls=[];$choices=[];$entities=[];
        foreach($p['plan']['calls'] as $call){
            $tool=$call['tool']??'';$def=collect($tools)->firstWhere('name',$tool);
            if(!$def||!$runner->allowed($tool))return null;
            $args=$call['arguments']??[];abort_unless(is_array($args)&&count($args)<=12,422);
            if($tool==='get_operating_brief')$args=[];
            if($tool==='get_inventory_unit_totals'&&!in_array($args['unit']??null,$def['schema']['properties']['unit']['enum']??[],true))return null;
            if(in_array($tool,['get_sales_analytics','get_customer_analytics'],true)){
                $q=app(AssistantLanguage::class)->normalize($v['question']);
                $explicit=preg_match('/\d{4}-\d{2}|last month|last year|this month|this year|today|yesterday|this week|muaj(?:in|i)? (?:e )?kaluar|sot|dje|kete muaj|kete vit/',$q);
                $named=preg_match('/january|february|march|april|may|june|july|august|september|october|november|december|janar|shkurt|mars|prill|maj|qershor|korrik|gusht|shtator|tetor|nentor|dhjetor|\d+\s*(?:month|muaj|muj|day|dite|week|jave)/',$q);
                if($explicit||$named||empty($context['model_last_calls'])){
                    $period=app(AssistantPlanner::class)->period($v['question'],\App\Support\CompanyClock::timezone());
                    // An unhandled numeric duration cannot silently become the default period.
                    if(!$explicit && $named && preg_match('/\b\d+\s*(?:month|muaj|muj|day|dite|week|jave)/',$q))return null;
                    $args=['period'=>'custom','from'=>$period['from'],'to'=>$period['to']];
                }
            }
            unset($args['warehouse_term']);
            if($tool==='get_product_availability'&&!empty($hint['warehouse_term']))$args['warehouse_term']=$hint['warehouse_term'];
            $entity=$v['entity']??$context['entity']??null;
            if(!empty($call['entity_name'])){
                $needle=AssistantLanguage::identity((string)$call['entity_name']);
                $known=array_merge([$context['entity']??[]],$context['result_entities']??[]);
                if(!$needle||(!str_contains(AssistantLanguage::identity($v['question']),$needle)&&!collect($known)->contains(fn($e)=>AssistantLanguage::identity($e['title']??'')===$needle)))return null;
                $resolution=app(AssistantEntityResolver::class)->resolve(mb_substr((string)$call['entity_name'],0,80),in_array($call['entity_type']??null,['product','customer','supplier','warehouse','shipment','sales_order','purchase_order'],true)?$call['entity_type']:null);
                $entity=$resolution['entity'];if(!$entity){$choices=$resolution['choices'];break;}
            }
            foreach($def['schema']['properties']??[] as $key=>$schema)if(str_ends_with($key,'_id')){
                $type=substr($key,0,-3);
                // Arbitrary IDs proposed by a model are never trusted.
                if(isset($args[$key]))unset($args[$key]);
                if(($entity['type']??null)===$type)$args[$key]=(int)$entity['id'];
            }
            try{$data=$runner->execute($tool,$args);}catch(\Illuminate\Validation\ValidationException){return null;}
            if($tool==='get_customer_analytics'){
                $data['rows']=collect($data['rows']??[])->sortByDesc('revenue')->take(8)->values()->all();
                $data['qualification']='Ranked by recorded sales in the selected period; outstanding/overdue balances are current, not historical.';
            }
            $results[]=['tool'=>$tool,'data'=>$data];$calls[]=['tool'=>$tool,'arguments'=>$args];if($entity)$entities[]=$entity;
        }
        $sq=($v['language']??'en')==='sq';$composed=app(\App\Services\Platform\PlatformTrace::class)->measure('assistant.format',fn()=>app(AssistantComposer::class)->compose($results,$sq));
        if(!$results)return null;
        $fromCards=[];foreach($composed['cards'] as $card)if(!empty($card['entity']))$fromCards[]=$card['entity']+['title'=>$card['title']];
        $context['model_previous_question']=$v['question'];$context['model_last_calls']=$calls;$context['permission_scope']=$scope;
        if($entities){$context['entity']=$entities[0];$context['result_entities']=array_slice($entities,0,6);}
        if($fromCards)$context['result_entities']=array_slice($fromCards,0,6);
        $text=$results?($sq?'Dëshmi nga burimet e autorizuara; interpretimi është asistuar nga modeli lokal.':'Evidence from authorized sources; request interpretation assisted by the local model.'):mb_substr((string)($p['plan']['clarification']??($sq?'Cilin regjistrim nënkupton?':'Which record did you mean?')),0,600);
        // These headings are formatted from typed facts below; avoid an unused second inference.
        $direct=collect($results)->contains(fn($r)=>in_array($r['tool'],['get_product_availability','get_sales_analytics','get_inventory_unit_totals','get_customer_analytics','get_inventory_health','get_operating_brief'],true));
        $explanation=$results&&!$direct?app(LocalAssistantProvider::class)->explain(['cards'=>array_slice($composed['cards'],0,5),'limitations'=>$composed['limitations']],$sq):null;
        if($explanation)$text=$explanation;
        if(!$composed['cards'])$text=$sq?'Nuk u kthyen regjistrime që plotësojnë kushtet e kërkimit. Kontrollo burimin dhe kufizimet për datat ose dëshmitë që mungojnë.':'No qualifying records were returned. Check the source and its qualifications for missing dates or evidence.';
        foreach($results as $result){$data=$result['data'];
            if($result['tool']==='get_inventory_health'){
                $checks=array_filter($data['checks']??[],fn($c)=>($c['count']??0)>0);
                $text=$checks?($sq?'Ka kontrolle stoku që kërkojnë shqyrtim. Hap burimin për artikujt dhe gjendjen aktuale.':'Stock checks need review. Open the source for affected items and current state.'):($sq?'Nuk ka përjashtime në kontrollet e dukshme të stokut.':'No exceptions appear in the visible stock checks.');
            }
            if($result['tool']==='get_incoming_supply'&&!($data['rows']??[])){
                $text=($data['window']??'upcoming')==='overdue'
                    ? ($sq?'Nuk ka pranime të datuara me vonesë në këtë pamje të kufizuar.':'No overdue dated receipts appear in this bounded view.')
                    : ($sq?'Nuk ka ardhje të datuara deri më ':'No dated incoming supply appears through ').$data['through'].'.';
                $composed['next_action']=['url'=>'/purchase-orders','label'=>$sq?'Shqyrto afatet e porosive':'Review purchase-order dates'];
            }
            if($result['tool']==='get_customer_analytics')$text=$sq?'Klientët janë renditur sipas shitjeve të regjistruara në periudhën e zgjedhur. Borxhet e shfaqura janë aktuale.':'Customers ranked by recorded sales in the selected period. Shown receivable balances are current.';
            if($result['tool']==='get_inventory_plan'&&data_get($data,'plan.coverage_supported')===false){$text=$sq?'Nuk ka ende dëshmi të mjaftueshme për një sasi të besueshme riporosie. Shqyrto kërkesën dhe afatin e furnitorit në planifikim.':'A reliable reorder quantity is not available yet. Review demand evidence and supplier lead time in planning.';$composed['limitations'][]=$sq?'Mungesa nuk është konfirmuar nga një parashikim i kualifikuar.':'A shortage has not been confirmed by a qualified forecast.';}
            if($result['tool']==='get_sales_analytics')$text=($sq?'Shitjet operative: ':'Operational sales: ').\App\Support\Money::normalize($data['metrics']['revenue']).' '.\App\Support\CompanyCurrency::current().' · '.$data['period']['from'].' → '.$data['period']['to'].'. '.($sq?'Kjo nuk është fitim i postuar.':'This is not posted profit.');
            if($result['tool']==='get_product_availability')$text=($sq?'Në dispozicion: ':'Available: ').$data['position']['available'].' '.$data['product']['unit'].' · '.($sq?'Fizik: ':'Physical: ').$data['position']['physical'].' '.$data['product']['unit'];
            if($result['tool']==='get_inventory_unit_totals')$text=($sq?'Stoku i disponueshëm tani: ':'Available stock now: ').$data['available'].' '.$data['unit'];
        }
        $availability=array_values(array_filter($results,fn($r)=>$r['tool']==='get_product_availability'));
        if(count($availability)>1)$text=implode('; ',array_map(fn($r)=>$r['data']['product']['name'].': '.$r['data']['position']['available'].' '.$r['data']['product']['unit'],$availability)).'.';
        app(AnalyticsDataService::class)->audit('assistant.query',['response_id'=>$id,'intent'=>'model_tools','provider'=>'ollama','model'=>$p['model'],'model_revision'=>config('assistant.model_revision'),'tools'=>array_map(fn($c)=>['tool'=>$c['tool'],'status'=>'success'],$calls),'response_state'=>$results?'success':'needs_clarification','duration_ms'=>(int)(($p['duration_ns']??0)/1000000)]);
        return $composed+['id'=>$id,'conversation_id'=>$conversation,'intent'=>'model_tools','text'=>$text,'choices'=>$choices,'context'=>$context['entity']??null,'context_entities'=>$entities,'state'=>$results?'success':'needs_clarification','read_only'=>true,'provider'=>'ollama','model'=>$p['model'],'model_calls'=>$calls]+(config('assistant.diagnostics')?['model_timing'=>$p['timing']??null]:[]);
    }
}
