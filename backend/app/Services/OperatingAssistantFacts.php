<?php

namespace App\Services;

use Illuminate\Support\Str;

class OperatingAssistantFacts
{
    public function reply(array $input, string $conversation): ?array
    {
        $q = app(AssistantLanguage::class)->normalize($input['question']);
        $sq = ($input['language'] ?? 'en') === 'sq';
        if(preg_match('/what if|simulate|po nese|\bnese\b/',$q))return null;
        $runner=app(AssistantToolRunner::class);
        if (! isset($input['entity']) && ! preg_match('/[\d"“]|\b(?:only|vetem|from|prej|nga|next week|this week)\b/i', $q)
            && preg_match('/\bwhich\b/', $q)) {
            $stockout = preg_match('/\bproducts?\b.*\b(?:run(?:s|ning)? out|stockout|mbaroj\w*)\b/', $q);
            $shipmentRisk = preg_match('/\bshipments?\b.*\b(?:at risk|risk)\b/', $q);
            $tool = $stockout ? 'get_inventory_intelligence_recommendations' : ($shipmentRisk ? 'get_at_risk_shipments' : null);
            if ($tool && $runner->allowed($tool)) {
                $data = $runner->execute($tool, $stockout ? ['risk' => 'high', 'horizon' => 30] : []);
                $view = app(AssistantComposer::class)->compose([['tool' => $tool, 'data' => $data]], $sq);
                $url = AssistantComposer::URLS[$tool];
                $text = $stockout
                    ? ($sq ? 'Parashikimet e vlefshme me rrezik të lartë për mungesë stoku në 30 ditët e ardhshme.' : 'Current high-risk stockout forecasts for the next 30 days.')
                    : ($sq ? 'Dërgesat që burimi i shënon për shqyrtim.' : 'Shipments flagged for review by the source.');
                if (! $view['cards']) {
                    $text .= $sq ? ' Nuk u gjetën raste në këtë pamje. Disa regjistrime mund të mos kenë dëshmi të mjaftueshme.' : ' This view returned no matches. Some records may lack enough evidence to assess.';
                }
                return $view + ['id' => (string) Str::uuid(), 'conversation_id' => $conversation,
                    'intent' => $stockout ? 'stockout' : 'shipments', 'text' => $text,
                    'next_action' => ['url' => $view['cards'][0]['url'] ?? $url, 'label' => $sq ? 'Shqyrto burimin' : 'Review the source'],
                    'provider' => 'deterministic', 'state' => 'success', 'read_only' => true, 'tools' => $runner->executions];
            }
        }
        if(preg_match('/(?:deliver|dispatch|pick|dergo|dorez|pergatit).*(?:order|today)|order.*(?:deliver|dispatch|pick|ready|gati)/',$q)&&$runner->allowed('get_orders_needing_attention')){
            $view=preg_match('/blocked|shortage|backorder|bllok|munges/',$q)?'backorders':(preg_match('/pick|pergatit/',$q)?'ready_to_allocate':'packed');
            $data=$runner->execute('get_orders_needing_attention',['view'=>$view]);$cards=[];
            foreach($data['data']??[] as $row)if(!empty($row['sales_order_id']))$cards[]=['tool'=>'get_orders_needing_attention','title'=>$row['order']['order_number'],'summary'=>$row['states']['fulfillment']??'','url'=>'/fulfillment?order='.$row['sales_order_id'],'metrics'=>[]];
            $text=$cards?($sq?'Këto porosi janë gati për shqyrtimin e hapit të radhës në depo.':'These orders are ready for the next warehouse review.'):($sq?'Nuk ka porosi në këtë gjendje për shqyrtim.':'No orders in this state are available for review.');
            return ['id'=>(string)Str::uuid(),'conversation_id'=>$conversation,'intent'=>'fulfillment_review','text'=>$text,'meaning'=>$sq?'Kontrollo përgatitjen dhe autorizimin para nisjes; kjo nuk është premtim dorëzimi.':'Check preparation and authorization before dispatch; this is not a delivery promise.','next_action'=>['url'=>'/fulfillment','label'=>$sq?'Hap përgatitjen dhe nisjen':'Open picking & dispatch'],'cards'=>$cards,'sources'=>[['tool'=>'get_orders_needing_attention','url'=>'/order-hub?view='.$view]],'limitations'=>[$sq?'Deri dhjetë porosi; gjendja kontrollohet sërish në burim.':'Up to ten orders; current state is rechecked in the workflow.'],'choices'=>[],'read_only'=>true,'provider'=>'deterministic','state'=>'success','tools'=>$runner->executions];
        }
        if(preg_match('/supplier.*late|late.*supplier|(?:receipt|pranim|supply|furnizim).*(?:late|overdue)|late.*receipt/',$q)&&$runner->allowed('get_incoming_supply')){
            return $this->overdueReceipts($runner, $conversation, $sq);
        }
        $task = ($input['entity']['type'] ?? null) === 'task' && preg_match('/why|explain|pse|shpjego|prepare|pergatit|next|safe/', $q);
        $collections = preg_match('/(?:customer|collection|klient|arketime).*(?:follow.?up|task|ndjek|dety)|(?:follow.?up|ndjek).*(?:customer|collection|klient)/', $q);
        $overdue = (bool) preg_match('/overdue|late|vonuar|vones/', $q);
        $daily=(bool)preg_match('/what needs my attention|today in aims|warehouse.*(?:today|urgent)|(?:what|which).*(?:do|review|work|urgent).*(?:warehouse|today)/',$q);
        if($daily&&!app(AssistantToolRunner::class)->allowed('get_operating_brief')){
            if(app(AssistantToolRunner::class)->allowed('get_inventory_health')){
                $reply=app(InventoryAssistantFacts::class)->reply('inventory_health',$conversation,$sq);
                $reply['limitations'][]=$sq?'Radha e detyrave nuk lejohet për këtë llogari; kjo pamje tregon vetëm kontrollet e inventarit.':'Task queues are not permitted for this account; this view shows inventory controls only.';
                return $reply;
            }
            return ['id'=>(string)Str::uuid(),'conversation_id'=>$conversation,'intent'=>'daily_navigation','text'=>$sq?'Kjo llogari nuk ka qasje në radhën e detyrave. Fillo nga hapësira e punës që të lejohet.':'This account cannot view task queues. Start in your permitted workspace.','cards'=>[],'sources'=>[],'choices'=>[],'navigation_actions'=>[['url'=>'/procurement','en'=>'Purchase requests','sq'=>'Kërkesat e blerjes'],['url'=>'/warehouse-mobile','en'=>'Warehouse work','sq'=>'Puna në depo'],['url'=>'/customers','en'=>'Customers','sq'=>'Klientët']],'limitations'=>[$sq?'Vetëm lidhje navigimi; asnjë numër detyrash nuk është konfirmuar.':'Navigation only; no task counts have been confirmed.'],'read_only'=>true,'provider'=>'deterministic','state'=>'insufficient'];
        }
        $executive = empty($input['entity']) && preg_match('/business.*(?:doing|perform)|focus.*today|what.*changed|what.*happened.*(?:yesterday|today|week|aims)|ndrysh.*(?:jave|sot)|si jemi.*(?:shitje|debt)|what.*kontroll.*sot|afford.*purchas|shipment.*(?:affect|attention)|customer.*(?:contact|follow)|stock.*(?:ordering|reorder)|what needs my attention|today in aims/', $q);
        if (($executive||$daily) && !($collections && $overdue)) return $this->management($input, $conversation, $q, $sq);
        $warehouseDay=(bool)preg_match('/(?:warehouse|depo).*(?:today|sot)|(?:what|cfare).*(?:bo|do|work|pune).*(?:warehouse|depo)/',$q);
        $brief = $warehouseDay || ($collections && $overdue) || preg_match('/what needs my attention|most important unresolved|what can.*safely resolve|today in aims|operating brief|punet.*sot|qka.*(?:sot|vemend)|cfare.*vemend/', $q);
        if (! $task && ! $brief) {
            return null;
        }
        $runner = app(AssistantToolRunner::class);
        $cards = [];
        if ($task) {
            $data = $runner->execute('get_task_review', ['task_id' => (int) $input['entity']['id']]);
            $tool = 'get_task_review';
            $url = '/action-center?task='.$data['task']['id'].'&status=all';
            $metrics = [];
            foreach (['available' => ['Available', 'Në dispozicion'], 'reserved' => ['Reserved', 'Rezervuar'], 'incoming' => ['Incoming', 'Në ardhje'], 'overdue' => ['Overdue', 'Me vonesë']] as $key => $label) {
                if (isset($data['current_evidence'][$key])) {
                    $metrics[] = ['label' => $label[$sq ? 1 : 0], 'value' => $data['current_evidence'][$key], 'unit' => ''];
                }
            }
            $cards[] = ['tool' => $tool, 'title' => $data['task']['title'], 'url' => $url, 'summary' => $data['priority_reason'], 'metrics' => $metrics,
                'evidence' => ['trigger_evidence' => $data['trigger_evidence'], 'current_evidence' => $data['current_evidence'], 'recommendation' => $data['recommendation'], 'workflow' => $data['workflow']]];
            $text = $sq ? 'Detyra ruan dëshminë fillestare dhe gjendjen aktuale. Hapni rishikimin për pronësi, premtim pagese ose përgatitje drafti me konfirmim.' : 'The task preserves its trigger evidence and current state. Open its review to claim it, record a payment promise, or explicitly prepare and confirm a draft.';
        } else {
            $tool = 'get_operating_brief';
            $data = $runner->execute($tool, $warehouseDay ? ['team'=>'warehouse'] : ($collections ? ['team' => 'sales', 'overdue' => $overdue] : []));
            $url = '/action-center';
            foreach ($data['tasks'] as $row) {
                $cards[] = ['tool' => $tool, 'title' => $row['title'], 'url' => '/action-center?task='.$row['id'].'&status=all', 'summary' => $row['priority'], 'metrics' => []];
            }
            foreach (array_slice($data['issues'], 0, max(0, 12 - count($cards))) as $row) {
                $cards[] = ['tool' => $tool, 'title' => $row['title'], 'url' => $row['url'], 'summary' => $row['description'] ?? '', 'metrics' => []];
            }
            $text = $sq ? 'Punët dhe përjashtimet aktuale të lejuara janë më poshtë. Afatet dhe urgjenca e deklaruar përcaktojnë rendin.' : 'Current permitted work and exceptions are shown below. Due dates and explicit urgency determine priority.';
            if($warehouseDay&&$cards)$text=$sq?'Fillo me punën e parë në listë. Hap burimin për të parë nëse pranimi, përgatitja ose dërgimi mund të vazhdojë.':'Start with the first item. Open its source to check whether receiving, picking or dispatch can proceed.';
            if (! $cards) {
                $text .= $sq ? ' Nuk ka punë në këtë pamje.' : ' No work appears in this bounded view.';
            }
        }

        return ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => $task ? 'explain' : 'brief', 'text' => $text, 'cards' => $cards,
            'sources' => [['tool' => $tool, 'url' => $url]], 'limitations' => [$sq ? 'Vetëm lexim. Veprimet me pasoja kërkojnë kontrollin dhe miratimin përkatës.' : 'Read-only. Meaningful writes require their structured source review and applicable approval.'],
            'choices' => [], 'follow_ups' => [], 'read_only' => true, 'provider' => 'deterministic', 'state' => 'success', 'tools' => $runner->executions, 'context' => null, 'context_entities' => []];
    }

    public function overdueReceipts(AssistantToolRunner $runner, string $conversation, bool $sq): array
    {
        $data=$runner->execute('get_incoming_supply',['window'=>'overdue']);$cards=[];
        foreach($data['rows'] as $row)$cards[]=['tool'=>'get_incoming_supply','title'=>$row['reference'].' · '.$row['name'],'summary'=>($sq?'Pritej më ':'Expected ').$row['expected_at'],'url'=>$row['url'],'metrics'=>[['label'=>$sq?'Në pritje':'Outstanding','value'=>$row['quantity'],'unit'=>$row['unit']]]];
        return ['id'=>(string)Str::uuid(),'conversation_id'=>$conversation,'intent'=>'overdue_supply','text'=>$cards?($sq?'Afati i pritshëm i këtyre pranimeve ka kaluar. Konfirmo datën e re me furnitorin.':'These expected receipt dates have passed. Confirm revised dates with the suppliers.'):($sq?'Nuk ka pranime të vonuara në këtë pamje të kufizuar.':'No overdue receipts appear in this bounded view.'),'cards'=>$cards,'next_action'=>['url'=>'/purchase-orders','label'=>$sq?'Shqyrto porositë e blerjes':'Review purchase orders'],'sources'=>[['tool'=>'get_incoming_supply','url'=>'/purchase-orders']],'limitations'=>[$sq?'Kjo nuk provon vendndodhjen e mallit ose shkakun e vonesës.':'This does not prove cargo location or the cause of delay.'],'read_only'=>true,'state'=>'success','provider'=>'deterministic','tools'=>$runner->executions];
    }

    private function management(array $input, string $conversation, string $q, bool $sq): array
    {
        $runner=app(AssistantToolRunner::class);
        $days=str_contains($q,'yesterday')?2:(preg_match('/today|sot/',$q)?1:(preg_match('/month|muaj/',$q)?30:7));
        $role=auth()->user()->role;$team=isset(AutomationOperations::TEAMS[$role])&&app(PermissionService::class)->roleHasPermission($role,AutomationOperations::TEAMS[$role])?$role:null;
        if(str_contains($q,'warehouse')&&app(PermissionService::class)->roleHasPermission($role,'inventory.view'))$team='warehouse';
        $data=$runner->execute('get_operating_brief',['view'=>'management','days'=>$days]+($team?['team'=>$team]:[]));
        $changed=(bool)preg_match('/changed|happened|ndrysh/',$q);
        $topic=preg_match('/customer.*(?:contact|follow)/',$q)?'customer':(preg_match('/stock.*(?:order|reorder)/',$q)?'stock':(str_contains($q,'shipment')?'shipment':null));
        $attention=$topic?array_values(array_filter($data['attention'],fn($r)=>$r['kind']===$topic)):$data['attention'];
        $first=$attention[0]??null;
        $firstTitle=$first?($first['kind']==='finance'?($sq?'kontrollet e hapjes dhe kontabilitetit':'opening and accounting controls'):OperatingBriefSummary::displayTitle($first,$sq)):null;
        $text=$first?($sq?'Fillo me shqyrtimin e '.$firstTitle.'. '.count($attention).' çështje kërkojnë vëmendje në këtë pamje.':'Start by reviewing '.$firstTitle.'. '.count($attention).' items need attention in this view.')
            :($sq?'Nuk ka çështje në këtë pamje të kufizuar. Kjo nuk certifikon gatishmërinë e kompanisë.':'No issues appear in this bounded view. This does not certify company readiness.');
        if($changed)$text=$sq?count($data['changes']['rows']??[]).' ngjarje të regjistruara në periudhën e shqyrtimit.':count($data['changes']['rows']??[]).' recorded events in the review period.';
        elseif(!$topic && $data['sales'] && preg_match('/business|si jemi/',$q))$text=($sq?'Aktiviteti i regjistruar i shitjeve ditore: ':'Recorded daily-sale activity: ').$data['sales']['document_total'].' '.$data['sales']['currency'].' ('.$data['period']['from'].' — '.$data['period']['to'].'). '.($sq?'Këto janë totale dokumentesh, jo fitim kontabël.':'These are document totals, not booked profit.');
        if(preg_match('/afford.*purchas/',$q))$text=$sq?'Aftësia për blerje kërkon shqyrtim të arkës, angazhimeve dhe kohës së arkëtimeve.':'Purchasing affordability requires review of cash, commitments and collection timing.';
        if($data['books'] && !$data['books']['values']) $text.=$sq?' Hapja dhe rakordimet e paverifikuara bllokojnë përfundimet për fitimin, arkën dhe aftësinë për blerje.':' Unverified opening balances or reconciliations block profit, cash and purchasing-affordability conclusions.';
        if($changed) $text.=$sq?' Ngjarjet e regjistruara nuk janë krahasim i gjendjeve historike.':' Recorded events are not a historical balance comparison.';
        if($data['unavailable']) $text.=$sq?' Disa burime nuk janë në dispozicion; gjendja aktuale nuk konfirmohet.':' Some sources are unavailable; current health is not confirmed.';
        $cards=[];
        if(!$changed && !$topic && $data['sales'] && preg_match('/business|si jemi/',$q)) $cards[]=['tool'=>'get_operating_brief','title'=>$sq?'Aktiviteti i shitjeve ditore':'Daily-sale activity','summary'=>$sq?'Totalet e dokumenteve; jo të ardhura të librit kryesor. Faturat e pavarura dhe kreditimet e mëvonshme përjashtohen.':'Document totals, not General Ledger revenue. Standalone invoices and later credits are excluded.',
            'url'=>$data['sales']['url'],'metrics'=>[['label'=>$sq?'Aktiviteti i regjistruar':'Recorded activity','value'=>$data['sales']['document_total'],'unit'=>$data['sales']['currency']]],'evidence'=>['basis'=>'operational_activity','from'=>$data['period']['from'],'to'=>$data['period']['to']]];
        if($changed) foreach(array_slice($data['changes']['rows']??[],0,5) as $r) $cards[]=['tool'=>'get_operating_brief','title'=>$sq?$r['label_sq']:$r['label'],'summary'=>$r['at'].' · '.$r['reference'],'url'=>$r['url'],'metrics'=>[]];
        else foreach(array_slice($attention,0,5-count($cards)) as $r) $cards[]=['tool'=>'get_operating_brief','title'=>$r['kind']==='finance'?($sq?'Shqyrto kontrollet kontabël':'Review accounting controls'):OperatingBriefSummary::displayTitle($r,$sq),
            'summary'=>$sq?'Hap burimin për dëshmitë aktuale, kufizimet dhe hapin e lejuar.':'Open the source for current evidence, limitations and the authorized next step.','url'=>$r['url'],'metrics'=>[], 'evidence'=>['basis'=>$r['basis'],'current'=>$r['evidence']]];
        if(!$cards)$text.=$sq?' Nuk ka ngjarje ose çështje në këtë pamje të kufizuar; kjo nuk certifikon gatishmërinë.':' No events or issues appear in this bounded view; this does not certify readiness.';
        return ['id'=>(string)Str::uuid(),'conversation_id'=>$conversation,'intent'=>'brief','text'=>$text,'cards'=>$cards,'sources'=>[['tool'=>'get_operating_brief','url'=>'/dashboard']],
            'limitations'=>[$sq?'Vetëm lexim. Nuk rindërtohen gjendje historike. Parashikimet nuk janë angazhime dhe veprimet kërkojnë miratim.':'Read-only. No historical balances are reconstructed. Forecasts are not commitments and actions require review and approval.'],
            'choices'=>[],'follow_ups'=>[],'read_only'=>true,'provider'=>'deterministic','state'=>'success','tools'=>$runner->executions,'context'=>null,'context_entities'=>[]];
    }
}
