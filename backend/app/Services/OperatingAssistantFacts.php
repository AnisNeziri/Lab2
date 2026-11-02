<?php

namespace App\Services;

use Illuminate\Support\Str;

class OperatingAssistantFacts
{
    public function reply(array $input, string $conversation): ?array
    {
        $q = app(AssistantLanguage::class)->normalize($input['question']);
        $sq = ($input['language'] ?? 'en') === 'sq';
        $task = ($input['entity']['type'] ?? null) === 'task' && preg_match('/why|explain|pse|shpjego|prepare|pergatit|next|safe/', $q);
        $collections = preg_match('/(?:customer|collection|klient|arketime).*(?:follow.?up|task|ndjek|dety)|(?:follow.?up|ndjek).*(?:customer|collection|klient)/', $q);
        $overdue = (bool) preg_match('/overdue|late|vonuar|vones/', $q);
        $executive = empty($input['entity']) && preg_match('/business.*(?:doing|perform)|focus.*today|what.*changed|ndrysh.*(?:jave|sot)|si jemi.*(?:shitje|debt)|what.*kontroll.*sot|afford.*purchas|shipment.*(?:affect|attention)|customer.*(?:contact|follow)|stock.*(?:ordering|reorder)|what needs my attention|today in aims/', $q);
        if ($executive && !($collections && $overdue)) return $this->management($input, $conversation, $q, $sq);
        $brief = ($collections && $overdue) || preg_match('/what needs my attention|most important unresolved|what can.*safely resolve|today in aims|operating brief|punet.*sot|qka.*(?:sot|vemend)|cfare.*vemend/', $q);
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
            $data = $runner->execute($tool, $collections ? ['team' => 'sales', 'overdue' => $overdue] : []);
            $url = '/action-center';
            foreach ($data['tasks'] as $row) {
                $cards[] = ['tool' => $tool, 'title' => $row['title'], 'url' => '/action-center?task='.$row['id'].'&status=all', 'summary' => $row['priority'], 'metrics' => []];
            }
            foreach (array_slice($data['issues'], 0, max(0, 12 - count($cards))) as $row) {
                $cards[] = ['tool' => $tool, 'title' => $row['title'], 'url' => $row['url'], 'summary' => $row['description'] ?? '', 'metrics' => []];
            }
            $text = $sq ? 'Punët dhe përjashtimet aktuale të lejuara janë më poshtë. Afatet dhe urgjenca e deklaruar përcaktojnë rendin.' : 'Current permitted work and exceptions are shown below. Due dates and explicit urgency determine priority.';
            if (! $cards) {
                $text .= $sq ? ' Nuk ka punë në këtë pamje.' : ' No work appears in this bounded view.';
            }
        }

        return ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => $task ? 'explain' : 'brief', 'text' => $text, 'cards' => $cards,
            'sources' => [['tool' => $tool, 'url' => $url]], 'limitations' => [$sq ? 'Vetëm lexim. Veprimet me pasoja kërkojnë kontrollin dhe miratimin përkatës.' : 'Read-only. Meaningful writes require their structured source review and applicable approval.'],
            'choices' => [], 'follow_ups' => [], 'read_only' => true, 'provider' => 'deterministic', 'state' => 'success', 'tools' => $runner->executions, 'context' => null, 'context_entities' => []];
    }

    private function management(array $input, string $conversation, string $q, bool $sq): array
    {
        $runner=app(AssistantToolRunner::class);
        $days=preg_match('/today|sot/',$q)?1:(preg_match('/month|muaj/',$q)?30:7);
        $data=$runner->execute('get_operating_brief',['view'=>'management','days'=>$days]);
        $changed=(bool)preg_match('/changed|ndrysh/',$q);
        $topic=preg_match('/customer.*(?:contact|follow)/',$q)?'customer':(preg_match('/stock.*(?:order|reorder)/',$q)?'stock':(str_contains($q,'shipment')?'shipment':null));
        $attention=$topic?array_values(array_filter($data['attention'],fn($r)=>$r['kind']===$topic)):$data['attention'];
        $first=$attention[0]??null;
        $firstTitle=$first?($first['kind']==='finance'?($sq?'kontrollet e hapjes dhe kontabilitetit':'opening and accounting controls'):$first['title']):null;
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
        else foreach(array_slice($attention,0,5-count($cards)) as $r) $cards[]=['tool'=>'get_operating_brief','title'=>$r['kind']==='finance'?($sq?'Shqyrto kontrollet kontabël':'Review accounting controls'):$r['title'],
            'summary'=>$sq?'Hap burimin për dëshmitë aktuale, kufizimet dhe hapin e lejuar.':'Open the source for current evidence, limitations and the authorized next step.','url'=>$r['url'],'metrics'=>[], 'evidence'=>['basis'=>$r['basis'],'current'=>$r['evidence']]];
        if(!$cards)$text.=$sq?' Nuk ka ngjarje ose çështje në këtë pamje të kufizuar; kjo nuk certifikon gatishmërinë.':' No events or issues appear in this bounded view; this does not certify readiness.';
        return ['id'=>(string)Str::uuid(),'conversation_id'=>$conversation,'intent'=>'brief','text'=>$text,'cards'=>$cards,'sources'=>[['tool'=>'get_operating_brief','url'=>'/dashboard']],
            'limitations'=>[$sq?'Vetëm lexim. Nuk rindërtohen gjendje historike. Parashikimet nuk janë angazhime dhe veprimet kërkojnë miratim.':'Read-only. No historical balances are reconstructed. Forecasts are not commitments and actions require review and approval.'],
            'choices'=>[],'follow_ups'=>[],'read_only'=>true,'provider'=>'deterministic','state'=>'success','tools'=>$runner->executions,'context'=>null,'context_entities'=>[]];
    }
}
