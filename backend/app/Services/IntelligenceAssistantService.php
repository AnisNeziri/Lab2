<?php
namespace App\Services;

use App\Contracts\IntelligenceProvider;
use App\Models\{ActivityLog,Customer,EnterpriseDecision,Product,Shipment,Supplier,PurchaseOrder,SalesOrder,Warehouse};
use App\Support\CompanyCurrency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Auth,Cache};
use Illuminate\Support\Str;

final class IntelligenceAssistantService
{
    private function key(string $id):string{return 'assistant-v9:'.Auth::user()->company_id.':'.Auth::id().':'.$id;}
    private function contextCache(){return Cache::store(app()->environment('testing')?'array':'file');}
    private function can(string $permission):bool{return app(PermissionService::class)->roleHasPermission(Auth::user()->role,$permission);}
    public function status():array
    {
        return ['core'=>'deterministic','provider'=>config('assistant.provider'),'configured'=>app(IntelligenceProvider::class)->available(),'model'=>$this->can('cms.manage')?config('assistant.local_model'):null,'data_egress'=>false,'local_only'=>true,'read_only_default'=>true,'context_minutes'=>config('assistant.context_minutes'),'max_tools'=>config('assistant.max_tools')];
    }
    public function ask(array $input):array
    {
        $reply=app(\App\Services\Platform\PlatformTrace::class)->measure('assistant.request',fn()=>$this->answer($input));
        if(!empty($reply['conversation_id'])){
            $id=$reply['conversation_id'];$context=$this->contextCache()->get($this->key($id),[]);
            $context['permission_scope']=hash('sha256',json_encode(app(PermissionService::class)->forRole(Auth::user()->role)));
            $context['last_intent']=$reply['intent']??null;
            if (isset($reply['purchase_comparison_reference'])) $context['purchase_comparison'] = $reply['purchase_comparison_reference'];
            else unset($context['purchase_comparison']);
            if (isset($reply['purchase_portfolio_reference'])) $context['purchase_portfolio'] = $reply['purchase_portfolio_reference'];
            else unset($context['purchase_portfolio']);
            if (isset($reply['fulfillment_reference'])) {
                $context['fulfillment_order_id'] = (int) $reply['fulfillment_reference'];
            } else {
                if (isset($context['fulfillment_order_id']) && empty($reply['context'])) {
                    unset($context['entity'], $context['result_entities']);
                }
                unset($context['fulfillment_order_id']);
            }
            $context['awaiting_order'] = ($reply['intent'] ?? null) === 'work_blocked'
                && ($reply['state'] ?? null) === 'needs_clarification';
            $firstRead = $reply['tools'][0] ?? $reply['model_calls'][0] ?? [];
            $context['review_tool'] = $firstRead['tool'] ?? null;
            $context['receipt_through'] = $context['review_tool'] === 'get_incoming_supply'
                ? ($firstRead['arguments']['through'] ?? null) : null;
            if ($context['review_tool'] === 'get_month_close_blockers') {
                $context['close_period'] = array_intersect_key($firstRead['arguments'] ?? [], ['from' => true, 'to' => true]);
            } else {
                unset($context['close_period']);
            }
            if (($reply['intent'] ?? null) === 'finance_payables' && isset($reply['tools'][0]['arguments']['due_through'])) {
                $context['payables_through'] = $reply['tools'][0]['arguments']['due_through'];
            } elseif (! in_array($reply['intent'] ?? null, ['finance_payables', 'workforce'], true)) {
                unset($context['payables_through']);
            }
            $entities=array_values(array_filter(array_map(fn($c)=>!empty($c['entity'])?$c['entity']+['title'=>$c['title']]:null,$reply['cards']??[])));
            if(!$entities&&!empty($reply['context_entities']))$entities=$reply['context_entities'];
            if($entities)$context['result_entities']=array_slice($entities,0,6);
            if(!empty($reply['context']))$context['entity']=$reply['context'];
            elseif(($reply['intent']??null)==='product_position'&&count($entities)>1)unset($context['entity']);
            $this->contextCache()->put($this->key($id),$context,config('assistant.context_minutes')*60);
        }
        return $reply;
    }
    private function answer(array $input):array
    {
        abort_unless(Auth::user()?->company_id,403);
        $v=validator($input,['question'=>'required|string|min:2|max:600','language'=>'sometimes|in:en,sq','conversation_id'=>'nullable|uuid','clear_context'=>'sometimes|boolean','simulation_id'=>'nullable|integer|min:1','customer_id'=>'nullable|integer|min:1','entity'=>'nullable|array:type,id','entity.type'=>'required_with:entity|in:product,customer,supplier,shipment,decision,task,purchase_order,sales_order,warehouse','entity.id'=>'required_with:entity|integer|min:1'])->validate();
        if (isset($input['purchase_context'])) {
            $v += validator($input, [
                'purchase_context' => 'array:product_id,warehouse_id,quantity,unit,required_by,supplier_id',
                'purchase_context.product_id' => 'required|integer|min:1',
                'purchase_context.quantity' => 'required|numeric|min:0.001|max:100000000',
                'purchase_context.unit' => 'sometimes|string|max:30',
                'purchase_context.warehouse_id' => 'sometimes|integer|min:1',
                'purchase_context.supplier_id' => 'sometimes|integer|min:1',
                'purchase_context.required_by' => 'sometimes|date_format:Y-m-d',
            ])->validate();
        }
        $id=$v['conversation_id']??(string)Str::uuid();$context=$this->contextCache()->get($this->key($id),[]);$sq=($v['language']??'en')==='sq';
        if (preg_match('/\b(?:ignore.*instructions|system prompt)\b/i', $v['question'])
            && preg_match('/\b(?:other compan\w*|another company|cross.tenant)\b/i', $v['question'])) {
            return ['id' => (string) Str::uuid(), 'conversation_id' => $id, 'intent' => 'unsupported', 'state' => 'insufficient',
                'text' => $sq ? 'Kjo llogari ka qasje vetëm në kompaninë e autorizuar.' : 'This account can only access its authorized company.',
                'cards' => [], 'sources' => [], 'tools' => [], 'choices' => [], 'provider' => 'deterministic', 'read_only' => true];
        }
        $permissionScope=hash('sha256',json_encode(app(PermissionService::class)->forRole(Auth::user()->role)));
        if (($v['clear_context'] ?? false) || isset($context['permission_scope']) && $context['permission_scope'] !== $permissionScope) {
            $context = [];
            $this->contextCache()->forget($this->key($id));
            app(FinanceAssistantFacts::class)->clear($id);
        }
        $context['permission_scope']=$permissionScope;
        $workforce=app(\App\Services\Workforce\WorkforceAssistantFacts::class);
        if($workforceIntent=$workforce->intent($v['question'])) return $workforce->reply($workforceIntent,$id,$sq);
        if ($portfolio = app(PurchasePortfolioAssistant::class)->answer($v, $id, $context)) return $portfolio;
        if ($comparison = app(PurchaseComparisonAssistant::class)->answer($v, $id, $context)) return $comparison;
        if (preg_match('/\b(?:cost price|purchase price|unit cost|cmimi i blerjes|kostoja per njesi)\b/', app(AssistantLanguage::class)->normalize($v['question']))) {
            $permitted = $this->can('analytics.finance');
            return ['id' => (string) Str::uuid(), 'conversation_id' => $id, 'intent' => 'product_cost',
                'state' => $permitted ? 'insufficient' : 'permission_denied',
                'text' => $permitted
                    ? ($sq ? 'Pyet për një kosto të regjistruar me periudhë dhe burim, si fatura ose vlerësimi i stokut.' : 'Specify the cost basis and source period, such as an invoice or stock valuation.')
                    : ($sq ? 'Kjo llogari nuk ka qasje në koston e produktit.' : 'This account cannot access product cost.'),
                'cards' => [], 'sources' => [], 'tools' => [], 'choices' => [], 'provider' => 'deterministic', 'read_only' => true];
        }
        $conversation = app(AssistantConversation::class);
        if ($history = $conversation->historicalVesselPosition($v, $id)) return $history;
        if ($followup = $conversation->fulfillmentFollowup($v, $id, $context)) return $followup;
        if ($purchase = $conversation->purchaseOrderReview($v, $id, $context)) return $purchase;
        if ($followup = $conversation->businessFollowup($v, $id, $context)) return $followup;
        if ($followup = $conversation->sourceReview($v, $id, $context)) return $followup;
        if($comparison=app(AssistantConversation::class)->compareReferences($v,$id,$context))return $comparison;
        if($explanation=app(AssistantConversation::class)->productExplanation($v,$id,$context))return $explanation;
        if(!isset($v['entity'])&&($selected=app(AssistantConversation::class)->selected($v['question'],$context)))$v['entity']=$this->authorizeEntity($selected);
        if(!isset($v['entity'])&&($reference=app(AssistantConversation::class)->referencedCustomers($v,$id,$context)))return $reference;
        if($comparison=app(AssistantConversation::class)->warehouseComparison($v,$id,$context))return $comparison;
        $v['question']=app(AssistantConversation::class)->question($v['question'],$context);
        if(config('assistant.model_tools')&&isset($v['entity']))$v['entity']=$this->authorizeEntity($v['entity']);
        if ($security=app(\App\Services\Security\SecurityAssistantFacts::class)->reply($v,$id)) return $security;
        if ($customerSales=app(CustomerSalesAssistantFacts::class)->reply($v,$id)) return $customerSales;
        if ($business=app(AssistantBusinessIntent::class)->reply($v,$id,$context)) return $business;
        if (app(AssistantPlanner::class)->plan($v['question'])['intent'] !== 'unsupported' && ($salesComparison=app(AssistantConversation::class)->salesComparison($v,$id))) return $salesComparison;
        if (empty($context['pending_capacity']) && ($stock=app(AssistantStockGuidance::class)->reply($v,$id))) return $stock;
        if ($work=app(AssistantWorkGuidance::class)->reply($v,$id,$context)) return $work;
        if ($operating=app(OperatingAssistantFacts::class)->reply($v,$id)) return $operating;
        $factIntent=app(AssistantPlanner::class)->plan($v['question'])['intent'];
        $finance=app(FinanceAssistantFacts::class);
        if($v['clear_context']??false)$finance->clear($id);
        if($financeIntent=$finance->intent($v['question'],$id)){
            if($financeIntent==='finance_action_boundary'||(!isset($v['entity'])&&!isset($v['customer_id'])))return $finance->reply($financeIntent,$v['question'],$id,$sq);
            $finance->clear($id);
        }
        if(in_array($factIntent,['inventory_health','incoming_supply','stock_commitments'],true)){
            $reply=app(InventoryAssistantFacts::class)->reply($factIntent,$id,$sq);
            if($factIntent==='incoming_supply'&&preg_match('/\b(?:china|country of origin|shteti i origjines)\b|\b(?:from|prej|nga)\s+(?!(?:the\s+)?(?:purchase orders?|pos?|suppliers?|furnitor\w*)\b)\p{L}/u',app(AssistantLanguage::class)->normalize($v['question']))){
                $reply['text']=$sq?'Origjina sipas shtetit nuk mund të verifikohet nga ky burim. Lista më poshtë përfshin të gjitha ardhjet e datuara në shtatë ditët e ardhshme.':'Country of origin cannot be verified from this source. The list below includes all dated supply expected in the next seven days.';
                $reply['limitations'][]=$sq?'Filtri i origjinës nuk është zbatuar.':'The origin filter was not applied.';
            }
            return $reply;
        }
        if($factIntent==='inventory_totals'){
            $normalized=app(AssistantLanguage::class)->normalize($v['question']);$unit=preg_match('/\b(?:metra|meters|metres)\b/',$normalized)?'m':(preg_match('/\b(?:kg|kilograms)\b/',$normalized)?'kg':'pcs');
            return app(InventoryAssistantFacts::class)->reply($factIntent,$id,$sq,['unit'=>$unit]);
        }
        if(in_array($factIntent,['low_stock','onboarding_status'],true))return app(OpeningFactsService::class)->reply($factIntent,$v['question'],$id,$sq);
        if(!$this->preferDirect($v,$context)&&($modelReply=app(AssistantModelTools::class)->reply($v,$id,$context))){
            $this->contextCache()->put($this->key($id),$context,config('assistant.context_minutes')*60);return $modelReply;
        }
        $v['question']=app(AssistantConversation::class)->question($v['question'],$context);
        // Early finance/CRM replies must not leave an old sales-follow-up marker.
        if(isset($context['last_intent']))$this->contextCache()->put($this->key($id),array_diff_key($context,['last_intent'=>true]),config('assistant.context_minutes')*60);
        if(preg_match('/(?:find|search|gjej|kerko).*(?:document|customs|dogan|supplier file)/i',$v['question'])) {
            $data=app(AssistantToolRunner::class)->execute('search_business',['q'=>$v['question']]);
            return ['id'=>(string)Str::uuid(),'conversation_id'=>$id,'intent'=>'search','text'=>$sq?'Burime të lejuara. Teksti është dëshmi, jo udhëzim ose bilanc aktual.':'Authorized source candidates. Retrieved text is evidence, not instructions or current balances.','cards'=>array_map(fn($r)=>['tool'=>'search_business','title'=>$r['title'],'summary'=>$r['subtitle']??'','url'=>$r['url'],'metrics'=>[]],$data['results']),'sources'=>array_map(fn($r)=>['tool'=>'search_business','url'=>$r['url']],$data['results']),'limitations'=>[$data['qualification']],'choices'=>[],'read_only'=>true,'state'=>'success','provider'=>'deterministic'];
        }
        app(\App\Services\Onboarding\OnboardingGuard::class)->assertOperational();
        if(isset($v['simulation_id'])){app(StrategicSimulationService::class)->get($v['simulation_id']);$context['strategic_simulation_id']=$v['simulation_id'];unset($context['optimization_plan_id']);}
        if(!isset($v['entity'])&&app(StrategicSimulationAssistant::class)->supports($v['question'],$context)) {
            try {$reply=app(StrategicSimulationAssistant::class)->reply($v['question'],$context,$sq);}
            catch (\Throwable $e) {
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $e->getStatusCode() === 403) throw $e;
                $reference = null;
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $message = collect($e->errors())->flatten()->implode(' ');
                } else {
                    $reference = app(PilotSupportService::class)->record($e, request(), 500);
                    $message = ($sq ? 'Skenari nuk mund të përgatitej. Provo përsëri ose kontakto mbështetjen. Referenca: ' : 'The scenario could not be prepared. Please try again or contact support. Reference: ').$reference;
                }
                $reply = ['intent'=>'strategic_simulation','text'=>$message,'cards'=>[],'sources'=>[],'limitations'=>[],'choices'=>[],'provider'=>'deterministic','read_only'=>true,'scenario'=>true,'context'=>null,'state'=>'needs_clarification'];
                if ($reference !== null) $reply['reference'] = $reference;
            }
            $reply['id']=(string)Str::uuid();$reply['conversation_id']=$id;$this->contextCache()->put($this->key($id),$context,config('assistant.context_minutes')*60);app(AnalyticsDataService::class)->audit('assistant.query',['response_id'=>$reply['id'],'conversation_id'=>$id,'question_length'=>mb_strlen($v['question']),'intent'=>'strategic_simulation','tools'=>[],'response_state'=>$reply['state'],'provider'=>'deterministic','follow_up'=>isset($v['conversation_id']),'scenario_isolated'=>true]);return $reply;
        }
        $runner=app(AssistantToolRunner::class);$plan=app(AssistantPlanner::class)->plan($v['question'],$context);$provider=['state'=>'deterministic'];
        // Only unknown phrasing can ask the local classifier. It never receives stored notes or records.
        if($plan['intent']==='record'&&!$plan['type']&&!preg_match('/["“]/u',$v['question'])&&app(IntelligenceProvider::class)->available()) {
            $provider=app(IntelligenceProvider::class)->respond([['role'=>'user','content'=>$v['question']]]);
            if(isset($provider['intent'])&&!in_array($provider['intent'],['prepare','scenario','record']))$plan['intent']=$provider['intent'];
            if(isset($provider['term']))$plan['term']=$provider['term'];
        }
        $answer=['id'=>(string)Str::uuid(),'conversation_id'=>$id,'intent'=>$plan['intent'],'text'=>'','cards'=>[],'sources'=>[],'limitations'=>[],'choices'=>[],'provider'=>$provider['state'],'read_only'=>true,'context'=>null];
        $results=[];$failures=[];$start=microtime(true);
        $read=function(string $tool,array $args=[])use($runner,&$results,&$failures){
            if(!$runner->allowed($tool)){$failures[]='permission:'.$tool;return null;}
            try{$data=$runner->execute($tool,$args);$results[]=['tool'=>$tool,'data'=>$data];return $data;}
            catch(\Throwable $e){if($e instanceof \Illuminate\Auth\Access\AuthorizationException||($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface&&$e->getStatusCode()===403))$failures[]='permission:'.$tool;
                else $failures[]=$tool.': '.($e instanceof \Illuminate\Validation\ValidationException?collect($e->errors())->flatten()->implode(' '):($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$e->getMessage():'The source could not be read. Please open the source page or try again.'));return null;}
        };
        $entity=$v['entity']??null;
        if(!$entity && empty($plan['term']) && preg_match('/\b(first|second|third)\s+(?:one|result|product)\b/', $plan['normalized']??'', $ordinal)) {
            $index=array_search($ordinal[1],['first','second','third']);
            $entity=$context['result_entities'][$index]??null;
        }
        if(!$entity&&!empty($plan['term'])&&!str_starts_with($plan['intent'],'optimization_')&&!in_array($plan['intent'],['learning','overrides','policy_suggestions','challengers','brief','risks','cash','inactive','opportunities','decisions','sales','changes'])) {
            $resolution=app(AssistantEntityResolver::class)->resolve($plan['term'],$plan['type']);
            if($resolution['entity']){
                $entity=$resolution['entity'];
                if($resolution['fuzzy'])$answer['resolution']=$sq?'Gjeta '.$entity['title'].' dhe e përdora për këtë përgjigje.':'I found '.$entity['title'].' and used it for this answer.';
            }else{
                $answer['choices']=$resolution['choices'];$answer['text']=$answer['choices']?($sq?'Cilin regjistrim nënkupton? Zgjidhe më poshtë; nuk do ta hamendësoj.':'Which record did you mean? Choose below; I will not guess.'):($sq?'Nuk gjeta një regjistrim të lejuar me këtë emër. Provo emrin ose SKU-në.':'I could not find an authorized record with that name. Try its name or SKU.');
                $plan['intent']='clarification';unset($context['entity'],$context['scenario'],$context['first_decision'],$context['result_entities']);
                if($plan['type']!=='shipment')unset($context['product']);
            }
        }
        if($entity){$entity=$this->authorizeEntity($entity);if(($context['entity']??null)!==$entity){$pendingCapacity=$context['pending_capacity']??null;$optimizationId=$context['optimization_plan_id']??null;$relatedProduct=$context['product']??null;$context=[];if($relatedProduct&&$entity['type']==='shipment')$context['product']=$relatedProduct;if(str_starts_with($plan['intent'],'optimization_')&&$optimizationId)$context['optimization_plan_id']=$optimizationId;if($pendingCapacity&&$entity['type']==='product'){$context['pending_capacity']=$pendingCapacity;$plan['intent']='capacity';}}$context['entity']=$entity;}
        $entity=$context['entity']??null;$intent=$plan['intent'];
        if(($entity['type']??null)==='product')$context['product']=$entity;
        if(($entity['type']??null)==='decision'){
            $decision=EnterpriseDecision::findOrFail($entity['id']);
            if($decision->product_id)$context['product']=$this->authorizeEntity(['type'=>'product','id'=>$decision->product_id]);
        }
        if(in_array($intent,['replenishment','stockout','compare'])&&isset($context['product']))$entity=$context['entity']=$context['product'];
        $needEntity=function(array $types)use($entity,$sq,&$answer):bool {
            if($entity&&in_array($entity['type'],$types))return false;
            $answer['text']=$sq?'Zgjidh '.implode(' / ',$types).' ose shkruaj emrin në thonjëza.':'Select a '.implode(' / ',$types).' or put its name in quotes.';return true;
        };
        if(str_starts_with($intent,'optimization_')){
            $args=$plan['optimization'];$pid=$args['plan_id']??$context['optimization_plan_id']??null;
            if($intent==='optimization_run'){$r=$read('optimize_supply_plan',array_intersect_key($args,array_flip(['horizon','commitment_limit'])));if(isset($r['id']))$context['optimization_plan_id']=$r['id'];}
            elseif($intent==='optimization_stale')$read('get_stale_optimization_plans');
            elseif(!$pid)$answer['text']=$sq?'Zgjidh një plan në Optimizuesin e furnizimit ose kërko të krijosh një plan.':'Choose a plan in Supply Optimizer, or ask to build a purchasing plan.';
            elseif($intent==='optimization_limit'&&isset($args['commitment_limit'])){$r=$read('simulate_commitment_limit',['plan_id'=>$pid,'commitment_limit'=>$args['commitment_limit']]);if(isset($r['id']))$context['optimization_plan_id']=$r['id'];}
            elseif($intent==='optimization_stress'){
                $stressArgs=['plan_id'=>$pid,'alternative'=>'balanced']+array_intersect_key($args,array_flip(['demand_multiplier','supplier_delay','shipment_delay','collections_delay']));$resolved=true;
                if(isset($args['supplier_term'])||isset($args['shipment_term'])){
                    $frozen=$read('get_optimization_plan',['plan_id'=>$pid]);
                    foreach(['supplier'=>['suppliers','name'],'shipment'=>['shipments','reference']] as $type=>[$collection,$field])if(isset($args[$type.'_term'])){
                        $term=Str::lower(Str::ascii($args[$type.'_term']));$matches=array_values(array_filter($frozen['stress_options'][$collection]??[],fn($row)=>Str::lower(Str::ascii($row[$field]??''))===$term));
                        if(count($matches)===1)$stressArgs[$type.'_id']=$matches[0]['id'];else{$resolved=false;$answer['text']=$sq?'Emri nuk përcakton një burim të vetëm në planin e ngrirë. Zgjidhe burimin në Optimizuesin e furnizimit.':'The name does not identify one source in this frozen plan. Select the source in Supply Optimizer.';}
                    }
                }elseif($entity&&in_array($entity['type'],['supplier','shipment']))$stressArgs[$entity['type'].'_id']=$entity['id'];
                if($resolved)$read('stress_test_supply_plan',$stressArgs);
            }
            elseif($intent==='optimization_explain'){
                $explainArgs=['plan_id'=>$pid]+array_intersect_key($args,array_flip(['decision_view']));$resolved=true;
                if(isset($args['product_term'])){
                    $frozen=$read('get_optimization_plan',['plan_id'=>$pid]);$term=Str::lower(Str::ascii($args['product_term']));
                    $matches=collect($frozen['result']['plans']??[])->flatMap(fn($a)=>$a['lines']??[])->pluck('product')->unique('id')->filter(fn($p)=>in_array($term,[Str::lower(Str::ascii($p['name']??'')),Str::lower(Str::ascii($p['sku']??''))],true))->values();
                    if($matches->count()===1)$explainArgs['product_id']=$matches[0]['id'];else{$resolved=false;$answer['text']=$sq?'Emri nuk përcakton një produkt të vetëm në planin e ngrirë. Zgjidhe produktin në Optimizuesin e furnizimit.':'The name does not identify one product in this frozen plan. Select the product in Supply Optimizer.';}
                }elseif(($entity['type']??null)==='product')$explainArgs['product_id']=$entity['id'];
                if($resolved)$read('explain_optimization_decision',$explainArgs);
            }
            else $read($intent==='optimization_compare'?'compare_optimization_plans':'get_optimization_plan',['plan_id'=>$pid]);
            if(!$answer['text'])$answer['text']=$sq?'Rezultate të strukturuara të optimizuesit lokal. Hap planin për statusin, krahasimin dhe rishikimin; asistenti nuk krijon porosi ose transferta.':'Structured local optimizer results. Open the plan for status, comparison and review; the assistant creates no orders or transfers.';
        }elseif(in_array($intent,['brief','risks'])) {
            // Existing V5 already applies severity and weighted risk. Keep its ordering.
            $read('get_open_decisions');$read('get_at_risk_shipments');$read('get_financial_pressure_periods');$read('get_at_risk_customers');$read('get_pending_approvals');
            $answer['text']=$sq?'Prioritetet e regjistruara, me vendimet më të rëndësishme përpara.':'Recorded priorities, with the highest-priority decisions first.';
        }elseif(in_array($intent,['learning','overrides','policy_suggestions','challengers'])) {
            $read(match($intent){'overrides'=>'get_override_patterns','policy_suggestions'=>'get_policy_suggestions','challengers'=>'get_challenger_status',default=>'get_decision_learning_summary'});
            if($intent==='learning')$read('get_recommendation_performance');
            $answer['text']=$sq?'Dëshmi historike dhe skenarë eksperimentalë, jo prova shkakësore. Vetëm politika e miratuar është aktive; sugjerimet nuk e ndryshojnë atë.':'Historical evidence and experimental scenarios, not causal proof. Only the approved champion is production; suggestions do not change it.';
        }elseif(in_array($intent,['replenishment','stockout'])) {
            if($entity&&$entity['type']==='product'){
                $read('get_product_availability',['product_id'=>$entity['id']]);
                $inventoryPlan=$read('get_inventory_plan',['product_id'=>$entity['id']]);
                if(empty($inventoryPlan['plan'])||empty($inventoryPlan['plan']['coverage_supported']))$answer['text']=$sq?'Nuk ka plan të kualifikuar për këtë produkt. Shqyrto dëshmitë në Planifikim; mungesa e të dhënave nuk është rekomandim blerjeje.':'No qualified plan is available for this product. Review evidence in Planning; missing data is not a purchase recommendation.';
                $read('get_product_incoming_risk',['product_id'=>$entity['id']]);
            }
            elseif($intent==='stockout'){
                $read('get_inventory_intelligence_recommendations',['risk'=>'high']);
                $read('get_inventory_intelligence_recommendations',['risk'=>'watch']);
                // Observed-history decisions remain useful when no ML champion
                // has passed promotion gates. Keep their own evidence qualification.
                $read('get_replenishment_decisions');
                if(preg_match('/incoming|shipment|arriv/', $plan['normalized']??''))$read('get_at_risk_shipments');
            }
            else $read('get_products_needing_replenishment');
        }elseif($intent==='suppliers'){
            $read('get_supplier_decisions');
            $answer['text']=$sq?'Shqyrto dëshmitë e furnitorëve. Renditja historike “më i miri” kërkon periudhë dhe kriter të verifikuar; kjo pamje nuk e shpall fituesin.':'Review supplier evidence. A historical “best” ranking requires a verified period and criterion; this view does not declare a winner.';
        }
        elseif($intent==='shipments')$read($entity&&$entity['type']==='shipment'?'get_shipment_intelligence':'get_at_risk_shipments',$entity&&$entity['type']==='shipment'?['shipment_id'=>$entity['id']]:[]);
        elseif($intent==='orders'){
            if(($entity['type']??null)==='purchase_order')$read('get_purchase_order',['purchase_order_id'=>$entity['id']]);
            elseif(($entity['type']??null)==='sales_order')$read('get_sales_order',['sales_order_id'=>$entity['id']]);
            else $read('get_orders_needing_attention');
        }
        elseif($intent==='cash')$read('get_cash_forecast',['horizon'=>preg_match('/\b(7|30|60|90)\b/',$v['question'],$m)?(int)$m[1]:30]);
        elseif($intent==='debt')$read($entity&&$entity['type']==='customer'?'get_customer':'get_receivable_intelligence',$entity&&$entity['type']==='customer'?['customer_id'=>$entity['id']]:[]);
        elseif($intent==='inactive')$read('get_at_risk_customers');
        elseif($intent==='opportunities')$read('get_sales_opportunities');
        elseif($intent==='decisions'){$read('get_open_decisions');$read('get_pending_approvals');}
        elseif($intent==='changes') {
            $period=app(AssistantPlanner::class)->period('yesterday',$this->timezone());
            $read('get_decision_changes',$entity&&$entity['type']==='decision'?['decision_id'=>$entity['id']]:['from'=>$period['from'],'to'=>CarbonImmutable::now($period['timezone'])->toDateString()]);
        }elseif($intent==='sales') {
            $period=app(AssistantPlanner::class)->period($v['question'],$this->timezone());
            $context['sales_period']=$period;
            // The analytics workspace owns period calculations and finance permissions.
            if($runner->allowed('get_sales_analytics')){
                $data=app(AnalyticsService::class)->workspace('sales',['period'=>'custom','from'=>$period['from'],'to'=>min($period['to'],today()->toDateString())]);
                $results[]=['tool'=>'get_sales_analytics','data'=>$data];$runner->executions[]=['tool'=>'get_sales_analytics','arguments'=>$period,'status'=>'success'];
                $answer['text']=($sq?'Shitjet ekonomike të regjistruara: ':'Recorded economic sales: ').\App\Support\Money::normalize($data['metrics']['revenue']).' '.\App\Support\CompanyCurrency::current().' · '.$data['period']['from'].' → '.$data['period']['to'].'. '.($sq?'Produktet renditen sipas vlerës së shitjeve; burimi dallon shitjet nga faturat e lidhura.':'Products are ranked by sales value; linked invoices and sales are deduplicated by the authoritative ledger.');
            }else $failures[]='permission:get_sales_analytics';
        }elseif(in_array($intent,['record','movements','forecast','explain','compare'])) {
            if(!$entity&&in_array($intent,['explain','compare'])) {
                // The inventory follow-up picks a decision from the prior brief, not an arbitrary product.
                $candidate=$context['first_decision']??null;
                if($candidate)$entity=$context['entity']=$this->authorizeEntity(['type'=>'decision','id'=>$candidate]);
            }
            if(!$needEntity(['product','customer','supplier','shipment','decision','task','purchase_order','sales_order','warehouse'])) {
                $type=$entity['type'];$tool=match($type){'product'=>$intent==='movements'?'get_stock_movements':($intent==='forecast'?'get_demand_forecast':($intent==='compare'?'get_supplier_options':($intent==='explain' && !preg_match('/available|physical|reserved|quality hold|disponuesh|rezervuar/',app(AssistantLanguage::class)->normalize($v['question']))?'get_inventory_plan':'get_product_availability'))),'customer'=>$intent==='record'?'get_customer':'get_customer_intelligence','supplier'=>$intent==='explain'?'get_supplier_performance':'get_supplier','shipment'=>'get_shipment_intelligence','decision'=>$intent==='compare'?'compare_decision_alternatives':'get_decision','task'=>'get_task','purchase_order'=>'get_purchase_order','sales_order'=>'get_sales_order','warehouse'=>'get_warehouse_stock'};
                $read($tool,[$type.'_id'=>$entity['id'], ...($tool === 'get_product_availability' && !empty($plan['warehouse_term']) ? ['warehouse_term'=>$plan['warehouse_term']] : [])]);
                if($intent==='explain'&&$type==='decision')$read('get_decision_changes',['decision_id'=>$entity['id']]);
            }
        }elseif(in_array($intent,['scenario','scenario_cash'])) {
            $scenario=$intent==='scenario_cash'?($context['scenario']??[]):$plan['scenario'];
            if(!$needEntity(['product','decision','shipment','customer'])) {
                $type=$entity['type'];
                if(isset($scenario['customer_delay_days'])){
                    if($type==='customer')$read('simulate_customer_payment_delay',['customer_id'=>$entity['id'],'delay_days'=>$scenario['customer_delay_days']]);
                    else $answer['text']=$sq?'Zgjidh klientin për këtë vonesë pagese.':'Select the customer for this payment-delay scenario.';
                }
                elseif(isset($scenario['next_month']))$answer['text']=$sq?'Shkruaj numrin e ditëve të vonesës; “muajin tjetër” nuk jep një datë të saktë mbërritjeje.':'Specify the number of delay days; “next month” is not an exact arrival date.';
                elseif(!$scenario)$answer['text']=$sq?'Shkruaj sasinë, ditët e vonesës ose përqindjen e kërkesës.':'Specify a quantity, delay days or demand percentage.';
                elseif(in_array($type,['product','decision'])) {
                    $product=$type==='product'?Product::findOrFail($entity['id']):EnterpriseDecision::findOrFail($entity['id'])->product;
                    if(isset($scenario['requested_unit'])&&!$this->unitMatches($scenario['requested_unit'],$product->unit))$answer['text']=$sq?'Njësia nuk përputhet me njësinë bazë. Zgjidh njësinë e saktë në planifikim.':'That unit does not match the base unit. Choose the exact unit in planning.';
                    else {
                        if(isset($scenario['arrival_delay_days'])) {
                            $incoming=$read('get_product_incoming_risk',['product_id'=>$product->id]);
                            $shipments=collect($incoming['shipments']??[])->filter(fn($s)=>!($s['evidence']['completed']??false))->unique('shipment_id')->values();
                            if($shipments->count()===1){
                                $shipment=$this->authorizeEntity(['type'=>'shipment','id'=>$shipments[0]['shipment_id']]);
                                $context['entity']=$shipment;
                                $read('simulate_shipment_delay',['shipment_id'=>$shipment['id'],'delay_days'=>$scenario['arrival_delay_days']]);
                            }else{
                                $answer['text']=$shipments->isEmpty()?($sq?'Nuk ka dërgesë hyrëse të vlerësuar për këtë produkt.':'No evaluated incoming shipment is recorded for this product.'):($sq?'Cilën dërgesë duhet ta vonojmë në këtë skenar?':'Which incoming shipment should be delayed in this scenario?');
                                $answer['choices']=$shipments->take(6)->map(fn($s)=>['type'=>'shipment','id'=>$s['shipment_id'],'title'=>$s['evidence']['reference'],'url'=>'/shipments/my-shipments?shipment='.$s['shipment_id']])->all();
                            }
                        }
                        unset($scenario['requested_unit']);$allowed=array_intersect_key($scenario,array_flip(['base_quantity','delay_days','demand_multiplier']));
                        if($intent==='scenario_cash') {
                            $planData=$read('get_inventory_plan',['product_id'=>$product->id]);$supplier=$planData['plan']['supplier_id']??null;
                            if(!$supplier)$answer['text']=$sq?'Mungon furnitori me çmim për të llogaritur ndikimin në para.':'A priced supplier is required to calculate the cash impact.';
                            else $read('simulate_purchase_cash_impact',array_merge(['currency'=>CompanyCurrency::current(),'product_id'=>$product->id,'supplier_id'=>$supplier,'horizon'=>30],array_intersect_key($allowed,array_flip(['base_quantity','demand_multiplier']))));
                            if(isset($allowed['delay_days']))$answer['limitations'][]=$sq?'Vonesa e furnizimit nuk është ndryshim i afatit kontraktual të pagesës.':'Supply delay is not a change to contractual payment terms.';
                        }elseif(!isset($scenario['arrival_delay_days'])){$read($type==='decision'?'simulate_decision_change':'simulate_inventory_scenario',[$type.'_id'=>$entity['id'],'scenarios'=>[[], $allowed]]);$context['scenario']=$allowed;}
                    }
                }elseif($type==='shipment'&&isset($scenario['arrival_delay_days']))$read('simulate_shipment_delay',['shipment_id'=>$entity['id'],'delay_days'=>$scenario['arrival_delay_days']]);
                else $answer['text']=$sq?'Ky skenar nuk mbështetet nga një mjet ekzistues.':'No existing tool supports this exact scenario.';
            }
            $answer['scenario']=true;$answer['read_only']=true;
        }elseif($intent==='capacity') {
            if($entity&&$entity['type']==='customer')$context['pending_capacity']=['customer_id'=>$entity['id'],'quantity'=>$plan['quantity']??null];
            if(!$needEntity(['product'])) {
                $customer=$v['customer_id']??$context['pending_capacity']['customer_id']??null;$quantity=$plan['quantity']??$context['pending_capacity']['quantity']??null;
                if($customer&&$quantity){$read('get_customer_supply_capacity',['product_id'=>$entity['id'],'customer_id'=>$customer,'quantity'=>$quantity]);unset($context['pending_capacity']);}
                else{$read('get_product_availability',['product_id'=>$entity['id']]);$read('get_inventory_plan',['product_id'=>$entity['id']]);}
                $read('get_product_incoming_risk',['product_id'=>$entity['id']]);
                $answer['text']=$sq?'Kontrollo stokun e disponueshëm, mbërritjet dhe kërkesën. Kapaciteti fizik nuk është miratim kredie për klientin.':'Review available stock, arrivals and demand. Physical capacity is not customer credit approval.';
            }
        }elseif($intent==='prepare') {
            if(($entity['type']??null)==='product'){
                $read('get_inventory_plan',['product_id'=>$entity['id']]);
                $answer['text']=$sq?'Hap planifikimin për të shqyrtuar sasinë dhe dëshmitë. Pa rekomandim të kualifikuar dhe konfirmim njerëzor nuk përgatitet kërkesë blerjeje.':'Open planning to review quantity and evidence. A qualified recommendation and human confirmation are required before preparing a Purchase Request.';
                $answer['limitations'][]=$sq?'Nuk u krijua kërkesë, porosi apo pagesë.':'No request, order or payment was created.';
            }else
            if(!$needEntity(['decision'])) {
                $d=$read('get_decision',['decision_id'=>$entity['id']]);$alternative=$d['recommended']['key']??null;
                if($alternative&&$this->can('procurement.manage')&&$this->can('analytics.finance')) {
                    $review=app(EnterpriseDecisionService::class)->review($entity['id'],['alternative_key'=>$alternative,'options'=>$context['scenario']??[]],false);
                    $action=['decision_id'=>$entity['id'],'alternative_key'=>$alternative,'options'=>$review['options'],'review_token'=>$review['review_token']];
                    $token=(string)Str::uuid();$this->contextCache()->put($this->key('review:'.$token),$action,600);
                    $answer['action']=['token'=>$token,'review'=>$review,'expires_minutes'=>10,'confirmation_required'=>true];
                    $answer['text']=$sq?'Rishiko draftin. Vetëm butoni i konfirmimit krijon kërkesën për blerje; nuk krijohet porosi ose pagesë.':'Review the draft. Only the confirmation button creates a Purchase Request; no PO or payment is created.';
                }else $answer['text']=$sq?'Mungon alternativë e realizueshme ose leja e krijimit të draftit.':'A feasible alternative and draft permission are required.';
            }
        }elseif($intent==='confirmation_required')$answer['text']=$sq?'Konfirmo vetëm nga paneli i rishikimit; një mesazh “po” nuk kryen veprime.':'Confirm only in the review panel; a chat message “yes” never performs an action.';
        elseif($intent==='unsupported')$answer['text']=$sq?'Nuk mund ta mbështes këtë kërkesë me mjete të autorizuara të AIMS. Pyet për një regjistrim ose rrezik konkret.':'I cannot support that request with authorized AIMS evidence tools. Ask about a specific record or risk.';
        $composed=app(AssistantComposer::class)->compose($results,$sq);
        if($intent==='record'&&($entity['type']??null)==='product')foreach($results as $result)if($result['tool']==='get_product_availability')$answer['text']=$result['data']['product']['name'].': '.$result['data']['position']['available'].' '.$result['data']['product']['unit'].($sq?' në dispozicion.':' available.');
        if($intent==='explain'&&($entity['type']??null)==='product')foreach($results as $result)if($result['tool']==='get_product_availability'){
            $position=$result['data']['position'];$unit=$result['data']['product']['unit'];
            $answer['text']=($sq?'Në dispozicion: ':'Available: ').$position['available'].' '.$unit.' · '.($sq?'Fizik: ':'Physical: ').$position['physical'].' '.$unit.' · '.($sq?'Rezervuar dhe në mbledhje: ':'Reserved and picking: ').($position['reserved']+$position['allocated']).' '.$unit.' · '.($sq?'Në kontroll, dëmtuar ose bllokuar: ':'Quality hold, damaged or blocked: ').($position['quality_hold']+$position['damaged']+$position['blocked']).' '.$unit.'.';
        }
        if(in_array($intent,['brief','risks'])){
            $seen=[];$counts=[];$composed['cards']=array_values(array_filter($composed['cards'],function($card)use(&$seen,&$counts){
                $tool=$card['tool'];$key=$tool.':'.$card['title'];if(isset($seen[$key]))return false;$seen[$key]=true;
                $counts[$tool]=($counts[$tool]??0)+1;return $counts[$tool]<=($tool==='get_open_decisions'?3:2);
            }));
        }
        foreach(['cards','sources','limitations'] as $field)$answer[$field]=array_merge($answer[$field],$composed[$field]);
        if(!empty($composed['presentation']))$answer['presentation']=$composed['presentation'];
        foreach($failures as $failure)$answer['limitations'][]=str_starts_with($failure,'permission:')?($sq?'Një burim nuk është i disponueshëm me lejet e tua.':'A source is unavailable with your permissions.'):$failure;
        if(!$answer['text'])$answer['text']=$answer['cards']?($sq?'Dëshmi nga regjistrimet e kompanisë. Vlerësimet janë këshilluese, jo garanci.':'Evidence from company records. Forecasts are advisory, not guarantees.'):($sq?'Nuk ka dëshmi të mjaftueshme për këtë pyetje.':'There is insufficient recorded evidence for this question.');
        if(!$entity)foreach($answer['cards'] as $card)if(($card['entity']['type']??'')==='decision'){$context['first_decision']=$card['entity']['id'];break;}
        if($answer['cards'])$context['result_entities']=array_values(array_filter(array_map(fn($c)=>!empty($c['entity'])?$c['entity']+['title'=>$c['title']]:null,$answer['cards'])));
        if($answer['choices'])$context['result_entities']=$answer['choices'];
        $context['last_intent']=$intent;
        $answer['context']=$context['entity']??null;
        $answer['context_entities']=array_values(collect([$context['product']??null,$context['entity']??null])->filter()->unique(fn($e)=>$e['type'].':'.$e['id'])->all());
        $answer['follow_ups']=match($intent){
            'shipments','scenario'=>[$sq?'Cilat produkte kan me mbet pa stok?':'Which products are at stock risk?',$sq?'Po nëse dërgesa vonohet 10 ditë?':'What if shipment is 10 days late?',$sq?'Çfarë duhet me porosit?':'How much should I order?'],
            'stockout','replenishment','explain','compare'=>[$sq?'Pse i pari?':'Why the first one?',$sq?'Cili furnitor mund ta mbulojë?':'Which supplier can cover?',$sq?'Po nëse dërgesa vonohet 10 ditë?':'What if shipment is 10 days late?'],
            'debt'=>[$sq?'Cili klient ka borxhin ma të madh?':'Which customer owes most?',$sq?'Parashikimi i parasë për 30 ditë':'Cash forecast for 30 days'],
            default=>[$sq?'Çfarë kërkon vëmendjen sot?':'What needs my attention today?',$sq?'Çfarë duhet me porosit?':'What should I order?'],
        };
        $context['last_intent']=$intent;
        $this->contextCache()->put($this->key($id),$context,config('assistant.context_minutes')*60);
        $state=$failures?'partial':($answer['choices']?'needs_clarification':($answer['cards']?'success':'insufficient'));
        app(AnalyticsDataService::class)->audit('assistant.query',['response_id'=>$answer['id'],'conversation_id'=>$id,'question_length'=>mb_strlen($v['question']),'intent'=>$intent,'entities'=>$answer['context'],'tools'=>array_map(fn($run)=>array_intersect_key($run,array_flip(['tool','status'])),$runner->executions),'scenario'=>$context['scenario']??null,'action_preview'=>isset($answer['action']),'response_state'=>$state,'provider'=>$provider['state'],'follow_up'=>isset($v['conversation_id']),'duration_ms'=>(int)((microtime(true)-$start)*1000),'language'=>$sq?'sq':'en']);
        $answer['cards']=array_map(fn($card)=>$card+['operation_kind'=>AimsToolRegistry::operationKind($card['tool'] ?? '')],$answer['cards']);
        $answer['state']=$state;$answer['limitations']=array_values(array_unique($answer['limitations']));
        return $answer;
    }

    private function timezone():string
    {
        $tz=Auth::user()->preferences['timezone']??config('app.timezone');return in_array($tz,timezone_identifiers_list(),true)?$tz:config('app.timezone');
    }
    private function unitMatches(string $input,string $unit):bool
    {
        $group=fn($x)=>in_array(Str::lower($x),['m','meter','metre','metres','meters','metra'])?'m':(in_array(Str::lower($x),['pcs','piece','pieces','cope'])?'pcs':Str::lower($x));return $group($input)===$group($unit);
    }
    /** Confident, simple reads use the same planner/tools without a model round-trip. */
    private function preferDirect(array &$v,array $context):bool
    {
        $plan=app(AssistantPlanner::class)->plan($v['question'],$context);$q=$plan['normalized']??'';
        if (($plan['intent'] ?? null) === 'sales' && preg_match('/\b(?:recorded sales|business sell)\b/', $q)
            && ! preg_match('/\b(?:customer|product|rank|top|most)\b/', $q)) return true;
        if(($plan['intent']??null)==='sales'&&preg_match('/last|this|today|yesterday|kaluar|kete|\d{4}-\d{2}/',$q)&&!preg_match('/customer|klient|january|february|march|april|may|june|july|august|september|october|november|december|janar|shkurt|mars|prill|maj|qershor|korrik|gusht|shtator|tetor|nentor|dhjetor/',$q))return true;
        if(($plan['intent']??null)!=='record'||($plan['type']??null)!=='product'||preg_match('/why|pse|explain|compare|krahas/',$q))return false;
        if(($v['entity']['type']??null)==='product')return true;
        if(empty($plan['term']))return false;
        $resolved=app(AssistantEntityResolver::class)->resolve($plan['term'],'product');
        if(!$resolved['entity']||$resolved['fuzzy'])return false;
        $v['entity']=$resolved['entity'];return true;
    }

    private function authorizeEntity(array $entity):array
    {
        $type=$entity['type'];$id=(int)$entity['id'];$permission=match($type){'product'=>'inventory.view','customer'=>'debts.view','supplier'=>'supplier_catalogue.view','shipment'=>'shipments.view','decision'=>'analytics.view','task'=>'tasks.view','purchase_order'=>'purchase_orders.view','sales_order'=>'fulfillment.view','warehouse'=>'inventory.view'};
        abort_unless($this->can($permission),403);
        $row=match($type){'product'=>Product::findOrFail($id),'customer'=>Customer::findOrFail($id),'supplier'=>Supplier::findOrFail($id),'shipment'=>Shipment::findOrFail($id),'decision'=>app(EnterpriseDecisionService::class)->detail($id),'task'=>app(ActionCenterService::class)->tasks(['task'=>$id,'status'=>'all'])->getCollection()->firstOrFail(),'purchase_order'=>PurchaseOrder::findOrFail($id),'sales_order'=>SalesOrder::findOrFail($id),'warehouse'=>Warehouse::findOrFail($id)};
        $title=data_get($row,'name')??data_get($row,'vessel_name')??data_get($row,'po_number')??data_get($row,'order_number')??data_get($row,'tracking_number')??data_get($row,'product.name')??data_get($row,'evidence.product.name')??data_get($row,'title');
        return ['type'=>$type,'id'=>$id,'title'=>$title];
    }
    public function confirm(array $input):array
    {
        $v=validator($input,['token'=>'required|uuid','confirm'=>'required|accepted','confirm_transfer_assumption'=>'sometimes|boolean'])->validate();
        $key=$this->key('review:'.$v['token']);$action=$this->contextCache()->get($key);abort_unless($action,409,'Review expired. Ask for a new preview.');
        // Existing V5 service rechecks permissions, company, live fingerprint, approvals and idempotency.
        $result=app(EnterpriseDecisionService::class)->draft($action['decision_id'],$action+['confirm_transfer_assumption'=>$v['confirm_transfer_assumption']??false]);
        app(AnalyticsDataService::class)->audit('assistant.action_confirmed',['decision_id'=>$action['decision_id'],'purchase_request_id'=>$result['purchase_request']->id,'reused'=>$result['reused']??false]);
        return ['purchase_request'=>$result['purchase_request']->only(['id','request_number','status']),'url'=>'/procurement?request='.$result['purchase_request']->id,'draft_only'=>true];
    }
    public function feedback(array $input):array
    {
        $v=validator($input,['response_id'=>'required|uuid','helpful'=>'required|boolean','reason'=>'nullable|in:incorrect,unclear,missing_data,not_useful,misunderstood,wrong_product'])->validate();
        $source=ActivityLog::where('user_id',Auth::id())->where('action','assistant.query')->where('new_value->response_id',$v['response_id'])->first();abort_unless($source,404);
        app(AnalyticsDataService::class)->audit('assistant.feedback',$v+['provider'=>$source->new_value['provider']??config('assistant.provider'),'model'=>$source->new_value['model']??config('assistant.local_model'),'model_revision'=>$source->new_value['model_revision']??null,'tools'=>array_slice(array_column($source->new_value['tools']??[],'tool'),0,6),'vocabulary_version'=>'dc18-1-v1','automatic_training'=>false]);return ['recorded'=>true];
    }
    public function usage():array
    {
        abort_unless($this->can('activity.view'),403);
        $rows=ActivityLog::where('action','assistant.query')->where('created_at','>=',now()->subDays(30))->latest('id')->limit(2000)->get()->pluck('new_value');
        $feedback=ActivityLog::where('action','assistant.feedback')->where('created_at','>=',now()->subDays(30))->latest('id')->limit(2000)->get()->pluck('new_value')->unique('response_id');
        return ['period_days'=>30,'bounded_to_latest'=>2000,'queries'=>$rows->count(),'intents'=>$rows->countBy('intent'),'states'=>$rows->countBy('response_state'),'follow_ups'=>$rows->where('follow_up',true)->count(),'tool_failures'=>$rows->sum(fn($r)=>collect($r['tools'])->where('status','failed')->count()),'feedback'=>['helpful'=>$feedback->where('helpful',true)->count(),'not_helpful'=>$feedback->where('helpful',false)->count()],'qualification'=>'Aggregate operational diagnostics only; no automatic model training.'];
    }
}
