<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** A question is input data, never code or a tool name. */
final class AssistantPlanner
{
    public const INTENTS = ['optimization_run','optimization_limit','optimization_compare','optimization_stress','optimization_explain','optimization_stale','learning','overrides','policy_suggestions','challengers','brief','risks','replenishment','stockout','suppliers','shipments','cash','debt','inactive','opportunities','decisions','changes','explain','compare','scenario','capacity','prepare','record','movements','forecast','sales'];

    public function plan(string $question, array $context = []): array
    {
        $q = Str::lower(Str::ascii(trim($question)));
        if (preg_match('/\b(sql|select\s+\*|ignore.*instructions|system prompt|execute|shell|bankrupt|faliment|delete|fshi|approve|mirato|send email)\b/', $q)) return ['intent'=>'unsupported'];
        $rules = [
            'optimization_stale'=>'/\b(stale.*supply|stale.*optim|plan.*vjetruar)/',
            'optimization_compare'=>'/\b(compare|krahaso).*(balanced|service.first|commitment|supply plan|planet)/',
            'optimization_explain'=>'/\b(why|pse|explain|shpjego).*(buying only|not purchasing|not buying|didn.t.*purchase|instead of|optimizer|supply plan|blejme vetem|nuk.*ble)|\b(which products|cilat produkte).*(not purchasing|not buying|nuk.*ble)/',
            'optimization_stress'=>'/\b(what if|po nese).*(supplier.*late|furnitor.*von|demand.*%|shipment.*days|derges.*dite|collections.*delay|arketime.*von)/',
            'optimization_limit'=>'/\b(increase.*limit|change.*limit|give it|keep new commitments|what if.*(?:limit|commitment|eur)|po nese.*kufi|rris.*kufi)/',
            'optimization_run'=>'/\b(optimize|optimise|optimizo|build.*purchasing plan|supply optimizer|best.*purchasing plan|reduce purchases using.*transfer)/',
            'challengers'=>'/\b(challenger|champion|policy versions|kandidat.*politik|politikat aktive)/',
            'overrides'=>'/\b(usually buy less|buy less than|override pattern|human.*decisions|blejme.*me pak|ndryshimet e sasise)/',
            'policy_suggestions'=>'/\b(policy change|policy suggest|safety.*policy|ndryshim.*politik|sugjerim.*politik)/',
            'learning'=>'/\b(how good.*recommend|recommend.*performance|poor.*recommend|recommend.*poor|aims improving|decision learning|mesimi i vendimeve|ciles.*rekomand|aims.*permireso)/',
            'prepare'=>'/\b(prepare|pergatit).*(option|recommend|draft|opsion|rekomand|kerkes)/',
            'scenario'=>'/\b(what if|po nese|simulate|simulo|only\s+[0-9]|vetem\s+[0-9])/',
            'changes'=>'/\b(what changed|changes since|changed since|cfare ndryshoi|ndryshimet|ndryshuar)/',
            'brief'=>'/\b(daily brief|morning brief|executive brief|business brief|brief today|summarize my business|what needs my attention|what should i focus on|permbledhje|prioritetet sot|cfare.*vemendjen)/',
            'capacity'=>'/\b(can we|can i|a mund).*(sell|fulfil|supply|handle|shes|plotes|furniz)/',
            'cash'=>'/\b(cash|cashflow|cash-flow|para|likuiditet|arke|cash forecast)/',
            'debt'=>'/\b(owes|owing|owe|receivable|borxh|debt)/',
            'inactive'=>'/\b(inactive|inactivity|stopped buying|joaktiv|nuk.*ble)/',
            'opportunities'=>'/\b(sales opportun|reorder opportun|customers.*(?:reorder|buy.*again)|mundesi.*shit|mundesi.*ri|klient.*riporos)/',
            'compare'=>'/\b(compare|instead of|versus| vs |krahaso|ne vend|supplier b.*supplier a)/',
            'explain'=>'/\b(why|explain|pse|shpjego)/',
            'shipments'=>'/\b(shipment|vessel|container|derges|anije|logistics)/',
            'suppliers'=>'/\b(supplier.*attention|supplier.*risk|supplier.*should.*consider|furnitor.*vemend|furnitor.*rrezik|furnitor.*duhet.*konsider)/',
            'replenishment'=>'/\b(what.*buy|should.*buy|what.*order|sa.*porosi|cfare.*ble|replenish|riporosi)/',
            'stockout'=>'/\b(stockout|run out|running out|out of stock|stock risk|risk.*stock|mbar.*stok|munges.*stok)/',
            'decisions'=>'/\b(waiting decision|pending decision|decisions|vendime|vendimet|miratime)/',
            'risks'=>'/\b(top risk|biggest risk|critical risk|rreziqet|rrezik)/',
            'movements'=>'/\b(movement|movements|levizje|levizjet)/',
            'forecast'=>'/\b(forecast|parashikim)/',
            'sales'=>'/\b(sales|shitje|shitjet)/',
        ];
        $intent = 'record';
        foreach ($rules as $name=>$pattern) if (preg_match($pattern,$q)) { $intent=$name; break; }
        if(!isset($context['optimization_plan_id'])&&!preg_match('/\bplan\s*#?\s*\d+/',$q)&&$intent==='optimization_stress')$intent='scenario';
        if(!isset($context['optimization_plan_id'])&&$intent==='optimization_limit')$intent='optimization_run';
        if(isset($context['optimization_plan_id'])&&in_array($intent,['explain','compare']))$intent='optimization_'.$intent;
        if (in_array($q,['yes','po','confirm','konfirmo'])) return ['intent'=>'confirmation_required'];
        $term = null;
        if (preg_match('/["“]([^"”]+)["”]/u',$question,$m)) $term=$m[1];
        elseif (preg_match('/(?:\bfor\b|\babout\b|\bper\b|\bpër\b)\s+(.+?)[?.!]*$/iu',$question,$m)) $term=trim($m[1]);
        elseif (preg_match('/^(?:stock|stoku|stok|find|gjej|customer|klienti|supplier|furnitori|product|produkti)\s+(.+?)[?.!]*$/iu',$question,$m)) $term=trim($m[1]);
        elseif ($intent === 'explain' && preg_match('/^(?:why\s+is|pse\s+(?:është|eshte))\s+(.+?)\s+(?:at\s+risk|në\s+rrezik|ne\s+rrezik)[?.!]*$/iu', $question, $m)) $term=trim($m[1]);
        $type = preg_match('/\b(customer|klient)/',$q)?'customer':(preg_match('/\b(supplier|furnitor)/',$q)?'supplier':(preg_match('/\b(shipment|vessel|derges|anije)/',$q)?'shipment':null));
        if (in_array($intent,['forecast','movements','replenishment','stockout','capacity']) && !$type) $type='product';
        $scenario=[];
        if ($intent==='scenario') {
            if(preg_match('/(?:buy|purchase|blej|only|vetem)\s*([0-9][0-9,. ]*)\s*(m|metres|meters|metra|pcs|pieces|cope)?\b/',$q,$m)) {
                $scenario['base_quantity']=(float)str_replace([',',' '],'',$m[1]);
                if(!empty($m[2]))$scenario['requested_unit']=$m[2];
            }
            if(preg_match('/(?:sales|demand|shitje|kerkesa).{0,15}?([0-9]+)\s*%/',$q,$m))$scenario['demand_multiplier']=1+((int)$m[1]/100);
            if(preg_match('/([0-9]+)\s*(?:days|day|dite)/',$q,$m)) {
                if(preg_match('/customer|klient|payment|pages/',$q))$scenario['customer_delay_days']=(int)$m[1];
                elseif(preg_match('/shipment|derges|arrival/',$q))$scenario['arrival_delay_days']=(int)$m[1];
                else $scenario['delay_days']=(int)$m[1];
            }
            if(preg_match('/next month|muajin tjeter/',$q))$scenario['next_month']=true;
        }
        if($intent==='cash'&&isset($context['scenario']))$intent='scenario_cash';
        $quantity=null;if($intent==='capacity'&&preg_match('/([0-9][0-9,. ]*)\s*(?:m|meters|metres|metra|pcs|pieces|cope)\b/',$q,$m))$quantity=(float)str_replace([',',' '],'',$m[1]);
        $optimization=['horizon'=>30];if(preg_match('/\b(30|60|90)\s*(?:days|day|dite)/',$q,$m))$optimization['horizon']=(int)$m[1];
        if(preg_match('/(?:€|eur\s*|limit\s*(?:of\s*)?|below\s*|under\s*|give it\s*)([0-9][0-9,]*(?:\.[0-9]{1,2})?)/',$q,$m))$optimization['commitment_limit']=(float)str_replace(',','',$m[1]);
        if(preg_match('/([0-9][0-9,]*(?:\.[0-9]{1,2})?)\s*(?:eur|euro)/',$q,$m))$optimization['commitment_limit']=(float)str_replace(',','',$m[1]);
        if(preg_match('/\bplan\s*#?\s*(\d+)/',$q,$m))$optimization['plan_id']=(int)$m[1];
        if($intent==='optimization_explain'){
            if(preg_match('/not purchasing|not buying|nuk.*ble/',$q))$optimization['decision_view']='not_purchasing';
            if(preg_match('/["“]([^"”]+)["”]/u',$question,$m))$optimization['product_term']=$m[1];
            elseif(!preg_match('/\binstead\s+of\b/iu',$question)&&preg_match('/(?:\bof\s+|\b(?:purchase|buy)\s+(?:product\s+)?)(.+?)(?:\s+(?:for|in)\s+plan\s*#?\s*\d+)?[?.!]*$/iu',$question,$m))$optimization['product_term']=trim($m[1]);
        }
        if($intent==='optimization_stress'){
            if(preg_match('/([0-9]+)\s*(?:days|dite)/',$q,$m))$optimization[preg_match('/collections|arketime/',$q)?'collections_delay':(preg_match('/shipment|derges/',$q)?'shipment_delay':'supplier_delay')]=(int)$m[1];
            $quoted=preg_match('/["“]([^"”]+)["”]/u',$question,$quote)?Str::lower(Str::ascii($quote[1])):null;
            if(preg_match('/supplier|furnitor/',$q)&&($quoted||preg_match('/(?:supplier|furnitori?)\s+(.+?)\s+(?:(?:is|eshte)\s+)?[0-9]+\s*(?:days|dite)/',$q,$m)))$optimization['supplier_term']=$quoted?:trim($m[1]);
            if(preg_match('/(?:shipment|dergesa?)\s+([a-z0-9_-]+)/',$q,$m))$optimization['shipment_term']=$quoted?:$m[1];
        }
        if(preg_match('/(?:demand|kerkesa).{0,15}([0-9]+)\s*%/',$q,$m))$optimization['demand_multiplier']=1+(int)$m[1]/100;
        return ['intent'=>$intent,'term'=>$term,'type'=>$type,'scenario'=>$scenario,'quantity'=>$quantity,'optimization'=>$optimization,'normalized'=>$q];
    }

    public function period(string $question, string $timezone): array
    {
        $q=Str::lower(Str::ascii($question));$date=CarbonImmutable::now($timezone);$start=$date;$end=$date;
        if(preg_match('/yesterday|dje/',$q))$start=$end=$date->subDay();
        elseif(preg_match('/this week|kete jave/',$q)){$start=$date->startOfWeek(1);$end=$date->endOfWeek(7);}
        elseif(preg_match('/this month|kete muaj/',$q)){$start=$date->startOfMonth();$end=$date->endOfMonth();}
        elseif(preg_match('/this year|kete vit/',$q)){$start=$date->startOfYear();$end=$date->endOfYear();}
        return ['from'=>$start->toDateString(),'to'=>$end->toDateString(),'timezone'=>$timezone];
    }
}
