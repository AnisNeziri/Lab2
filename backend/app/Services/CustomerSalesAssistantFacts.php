<?php
namespace App\Services;

use App\Models\{Customer,CustomerQuotation,DailySale};
use App\Support\{CompanyClock,CompanyCurrency,Money};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Read-only adapter into the existing typed assistant tools; never a command executor. */
class CustomerSalesAssistantFacts
{
    public function read(string $tool,array $input): array
    {
        $crm=app(CustomerWorkspaceService::class);$crm->permit();
        if($tool==='get_sales_followups'){
            abort_unless($crm->can('tasks.view'),403);
            $tasks=app(ActionCenterService::class)->tasks(['view'=>'mine','source_type'=>'Customer','status'=>'active','per_page'=>12])->items();
            return ['rows'=>array_values(array_filter($tasks,fn($t)=>$t['due_at']&&\Carbon\CarbonImmutable::parse($t['due_at'])->lte(CompanyClock::today()->endOfDay())))];
        }
        if($tool==='get_quotation_followups'){abort_unless($crm->can('fulfillment.view'),403);return ['rows'=>CustomerQuotation::where('status','sent')->whereDate('expires_on','<=',CompanyClock::today()->addDays(3))->orderBy('expires_on')->limit(12)->get(['id','number','customer_id','expires_on'])->toArray()];}
        $customer=Customer::findOrFail($input['customer_id']);
        if($tool==='get_customer_relationship')return $crm->customer($customer);
        abort_unless($tool==='get_customer_purchase_history'&&$crm->can('daily_sales.manage'),403);
        $start=CompanyClock::today()->subMonthNoOverflow()->startOfMonth();$end=$start->endOfMonth();
        $query=DailySale::where('customer_id',$customer->id)->whereBetween('sale_date',[$start->toDateString(),$end->toDateString()]);
        return ['customer'=>$customer->only(['id','name']),'from'=>$start->toDateString(),'to'=>$end->toDateString(),'currency'=>CompanyCurrency::current(),'count'=>(clone $query)->count(),'total'=>Money::normalize((clone $query)->sum('total_amount')),
            'rows'=>$query->with(['items'=>fn($q)=>$q->orderBy('id')])->latest('sale_date')->limit(10)->get()->map(fn($s)=>['id'=>$s->id,'number'=>$s->sale_number,'date'=>$s->sale_date,'total'=>$s->total_amount,'items'=>$s->items->take(10)->map(fn($i)=>['product'=>$i->product_name,'quantity'=>$i->quantity,'unit'=>$i->unit])->all()])->all()];
    }
    public function reply(array $input,string $conversation): ?array
    {
        $q=app(AssistantLanguage::class)->normalize($input['question']);$sq=($input['language']??'en')==='sq';$crm=app(CustomerWorkspaceService::class);
        $context=($input['entity']['type']??null)==='customer'?(int)$input['entity']['id']:(int)($input['customer_id']??0);
        $quotes=preg_match('/(?:quot(?:ation|e)|offer|ofert).*(?:follow|expir|ndjek|skad|afat|needs)|(?:follow|ndjek).*(?:quot|ofert)/',$q);
        $follow=preg_match('/(?:customer|klient|collection).*(?:follow.?up|ndjek).*(?:today|sot)|(?:which|cileve|cilat).*(?:follow.?up|ndjek).*(?:today|sot)/',$q);
        $follow = $follow || preg_match('/\b(?:customer|klient\w*)\b.*\b(?:contact|kontaktu\w*)\b.*\btoday\b/', $q)
            && ! preg_match('/\b(?:debt|balance|invoice|payments?|overdue|late|fatur\w*)\b/', $q);
        $next=$context&&preg_match('/(?:follow.?up|ndjekj|what.*next|cfare.*vijues)/',$q);
        $capacity=$context&&preg_match('/(?:credit|kredi).*(?:limit|kufi)|(?:before|para).*(?:limit|kufi)|how much.*order/',$q);
        $history=preg_match('/(?:buy|bought|purchase|bleu|bler|ble[tr]).*(?:last month|muajin.*kaluar)/',$q)&&($context||preg_match('/what did|qka bleu|cka bleu|cfare bleu/',$q));
        $writes=preg_match('/(?:approve|mirato|change|modify|ndrysho|pay|paguaj|post|write.?off|send|dergo|confirm|konfirmo).*(?:discount|zbrit|limit|kufi|payment|pages|order|porosi|message|mesazh)/',$q);
        if(!$quotes&&!$follow&&!$next&&!$capacity&&!$history&&!$writes)return null;
        if(!$crm->can('customers.manage')||!$crm->installed())return null;
        $cards=[];$sources=[];$tool=null;$runner=app(AssistantToolRunner::class);$text='';$limitations=[];
        if($writes)$text=$sq?'Ky veprim kërkon shqyrtim në procesin përkatës të shitjeve ose financave. Biseda nuk miraton zbritje, ndryshon kredi, poston pagesa, konfirmon porosi ose dërgon mesazhe.':'Use the authorized sales or finance review for that action. Chat cannot approve discounts, change credit, post payments, confirm orders or send messages.';
        elseif($quotes||$follow){
            $tool=$quotes?'get_quotation_followups':'get_sales_followups';$allowed=$quotes?$crm->can('fulfillment.view'):$crm->can('tasks.view');
            if(!$allowed)$text=$sq?'Kjo pamje kërkon lejet për ofertat ose detyrat e klientëve.':'This view requires quotation or customer-task permissions.';
            else{$data=$runner->execute($tool);foreach($data['rows'] as $r)$cards[]=['tool'=>$tool,'title'=>$r['number']??$r['title'],'summary'=>$quotes?($sq?'Skadon më ':'Expires ').substr($r['expires_on'],0,10):($sq?'Afati: ':'Due: ').substr($r['due_at'],0,10),'url'=>$quotes?'/customers?customer='.$r['customer_id'].'&tab=quotations&quote='.$r['id']:'/action-center?task='.$r['id'],'metrics'=>[]];$text=$quotes?($sq?'Oferta të dërguara që skadojnë brenda tre ditëve ose kanë skaduar.':'Sent quotations expiring within three days or already expired.'):($sq?'Ndjekjet e caktuara për ty, me afat sot ose të kaluar.':'Customer follow-ups assigned to you, due today or overdue.');if(!$cards)$text.=$sq?' Nuk ka regjistrime në këtë pamje.':' No records in this view.';}
        }else{
            if(!$context&&$history){$term=null;if(preg_match('/what did\s+(.+?)\s+(?:buy|purchase)/i',$input['question'],$m))$term=trim($m[1]);if($term){$matches=Customer::where(fn($q)=>$q->whereRaw('LOWER(name) = ?',[mb_strtolower($term)])->orWhereRaw('LOWER(business_name) = ?',[mb_strtolower($term)]))->limit(2)->get();if($matches->count()===1)$context=$matches->first()->id;}}
            if(!$context)$text=$sq?'Hap një klient ose përmend emrin e tij të saktë për të kufizuar këtë pyetje.':'Open a customer or name the exact customer to scope this question.';
            else{
                $tool=$history?'get_customer_purchase_history':'get_customer_relationship';
                if($history&&!$crm->can('daily_sales.manage'))$text=$sq?'Historia e blerjeve kërkon leje të shitjeve.':'Purchase history requires sales permissions.';
                else{$data=$runner->execute($tool,['customer_id'=>$context]);$url='/customers?customer='.$context;$sources[]=['tool'=>$tool,'url'=>$url];
                    if($history){$text=($sq?'Blerjet muajin e kaluar: ':'Last month purchases: ').$data['total'].' '.$data['currency'].' · '.$data['count'].' '.($sq?'shitje':'sales');foreach($data['rows'] as $r)$cards[]=['tool'=>$tool,'title'=>$r['number'],'summary'=>implode(', ',array_map(fn($i)=>$i['product'].' × '.$i['quantity'].' '.$i['unit'],$r['items'])),'url'=>'/daily-sales?sale='.$r['id'],'metrics'=>[]];}
                    elseif($capacity){$f=$data['finance'];$c=Customer::findOrFail($context);$text=!$f?($sq?'Për këtë kërkohen lejet e borxheve.':'Receivables permission is required.') : ($c->credit_status==='blocked'?($sq?'Llogaria është e bllokuar; kërko shqyrtim të autorizuar.':'This account is blocked; request authorized credit review.') : ($f['credit_limit']===null?($sq?'Nuk është caktuar kufi kredie.':'No credit limit is configured.') : ($sq?'Kapaciteti shtesë sipas politikës së kredisë: ':'Additional credit capacity under the existing policy: ').Money::maximum($f['available_credit'],'0.00').' '.$data['currency']));$limitations[]=$sq?'Ky nuk është premtim stoku ose miratim porosie.':'This is not a stock promise or order approval.';}
                    else{
                        $name=$data['customer']['business_name']?:$data['customer']['name'];
                        $due=collect($data['followups'])->filter(fn($r)=>!empty($r['due_at']))->sortBy('due_at')->first();
                        if($due)$text=$sq?'Fillo me ndjekjen “'.$due['title'].'” për '.$name.'. Hap detyrën dhe kontrollo kontaktin e fundit.':'Start with “'.$due['title'].'” for '.$name.'. Open the follow-up and check the latest contact.';
                        elseif((float)($data['finance']['overdue']??0)>0)$text=($sq?'Kontakto ':'Contact ').$name.($sq?' për pagesën e vonuar: ':' about the overdue balance: ').Money::normalize($data['finance']['overdue']).' '.$data['currency'].'. '.($sq?'Kontrollo pasqyrën dhe regjistro përgjigjen e klientit.':'Check the statement and record the customer’s response.');
                        elseif(($data['open_quotations']??0)>0)$text=$sq?'Shqyrto ofertat e hapura për '.$name.' dhe cakto kontaktin e radhës.':'Review the open quotations for '.$name.' and arrange the next contact.';
                        else $text=$sq?'Nuk ka ndjekje me afat ose pagesë të vonuar të konfirmuar në këtë pamje për '.$name.'. Shqyrto marrëdhënien dhe cakto kontaktin e radhës.':'No dated follow-up or overdue payment is confirmed in this view for '.$name.'. Review the relationship and plan the next contact.';
                        foreach($data['followups'] as $r)$cards[]=['tool'=>$tool,'title'=>$r['title'],'summary'=>$r['due_at']??'','url'=>'/action-center?task='.$r['id'],'metrics'=>[]];
                        $cards[]=['tool'=>$tool,'title'=>$name,'summary'=>$data['last_interaction']['occurred_at']??($sq?'Ende nuk ka kontakt të regjistruar.':'No contact recorded yet.'),'url'=>$url,'metrics'=>[]];
                        $input['next_action']=['url'=>$due?'/action-center?task='.$due['id']:$url,'label'=>$sq?'Shqyrto klientin dhe kontaktin':'Review customer & contact'];
                    }
                }
            }
        }
        foreach($cards as $card)$sources[]=['tool'=>$card['tool'],'url'=>$card['url']];
        if(!$writes&&($quotes||$follow)&&!empty($allowed)){
            $target=$quotes?'/customers?view=quotations':'/action-center?view=mine&status=active';
            $input['next_action']=['url'=>$target,'label'=>$quotes?($sq?'Shqyrto ofertat':'Review quotations'):($sq?'Hap ndjekjet e mia':'Open my follow-ups')];
            $sources[]=['tool'=>$tool,'url'=>$target];
        }
        return ['id'=>(string)Str::uuid(),'conversation_id'=>$conversation,'intent'=>'crm_review','text'=>$text,'cards'=>$cards,'sources'=>$sources,'limitations'=>[...$limitations,$sq?'Lista është e kufizuar; hap klientin për historinë e plotë.':'This list is bounded; open the customer for full history.'],'next_action'=>$input['next_action']??null,'choices'=>[],'follow_ups'=>[],'read_only'=>true,'provider'=>'deterministic','state'=>'success','tools'=>$runner->executions,'context'=>null,'context_entities'=>[]];
    }
}
