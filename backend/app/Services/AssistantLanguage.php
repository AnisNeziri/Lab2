<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Local vocabulary only. Entity names are resolved separately, never corrected blindly. */
final class AssistantLanguage
{
    public function normalize(string $text): string
    {
        $q = Str::lower(Str::ascii(trim($text)));
        $aliases = [
            'suplier'=>'supplier','suppliers'=>'supplier','furnitor'=>'supplier','furnitori'=>'supplier','furnitoret'=>'supplier',
            'costumer'=>'customer','costumers'=>'customer','customers'=>'customer','client'=>'customer','klient'=>'customer','klienti'=>'customer','kliente'=>'customer','klientet'=>'customer','konsumator'=>'customer',
            'invetory'=>'inventory','stok'=>'stock','stoku'=>'stock','stocku'=>'stock',
            'shipemnt'=>'shipment','shipments'=>'shipment','ship'=>'shipment','vessel'=>'shipment','dergese'=>'shipment','dergesa'=>'shipment','dergesat'=>'shipment','ngarkese'=>'shipment','anije'=>'shipment',
            'purhcase'=>'purchase','recivables'=>'debt','receivables'=>'debt','receivable'=>'debt','borxh'=>'debt','borxhin'=>'debt','borxhet'=>'debt',
            'depo'=>'warehouse','depot'=>'warehouse','magazine'=>'warehouse','produkt'=>'product','produkte'=>'product','produkti'=>'product','produktet'=>'product','artikull'=>'product',
            'porosi'=>'order','porosia'=>'order','porosite'=>'order','kines'=>'china','kina'=>'china','kine'=>'china',
            'osht'=>'is','eshte'=>'is','qka'=>'what','cfare'=>'what','cilat'=>'which','cili'=>'which','cilin'=>'which','sa'=>'how much',
            'porosit'=>'order','porosis'=>'order','duhet'=>'should','me'=>'me','mbet'=>'run out','met'=>'run out','vonese'=>'late','vone'=>'late','vonohet'=>'late','jane'=>'are',
            'mbuloje'=>'cover','parashikimi'=>'forecast','parasë'=>'cash','parase'=>'cash','kush'=>'which','preket'=>'risk',
            'mbarojne'=>'run out','ku'=>'where','presim'=>'incoming','na'=>'us','shume'=>'much','kerkon'=>'needs','vemendjen'=>'attention','time'=>'my',
        ];
        $q = preg_replace_callback('/\b[a-z]+\b/', fn ($m) => $aliases[$m[0]] ?? $m[0], $q);
        return trim(preg_replace('/\s+/', ' ', str_replace(['’', "'"], '', $q)));
    }

    public function entityTerm(string $question): ?string
    {
        if (preg_match('/["“]([^"”]+)["”]/u', $question, $m)) return trim($m[1]);
        $q = $this->normalize($question);
        // Remove controlled question vocabulary, not arbitrary unknown words/names.
        $q = preg_replace('/\b(?:whats|what|which|who|where|when|why|how|much|many|will|finish|show|find|check|tell|give|look|up|me|my|us|our|we|i|is|are|was|the|a|an|of|for|about|per|and|or|with|from|prej|in|on|at|to|by|has|have|kemi|qe|kan|ka|te|ma|madh|most|highest|risk|risks|high|low|stock|inventory|status|quantity|available|supplier|customer|product|products|warehouse|shipment|purchase|order|orders|po|sales|sale|debt|owes|owe|owing|better|best|can|cover|should|need|needs|buy|out|run|arrive|arrival|incoming|before|until|attention|late|days|day|dite|first|one|second|third|this|that|it|them|these|why|pse|explain|shpjego|please|sot|today|pa|ne|nga|do|does|get|si|cfare|cilat|cfar|need|happens|if|mund|i pari|pari)\b/', ' ', $q);
        $q = preg_replace('/\b(?:ta|nese|may)\b/', ' ', $q);
        $q = preg_replace('/\b(?:only|vetem)\s+(?=\d)/', '', $q);
        $q = trim(preg_replace('/\s+/', ' ', preg_replace('/[?!.,:]/', ' ', $q)));
        // Scenario amounts and ordinal follow-ups are not entity names.
        return $q !== '' && !preg_match('/^[\d ,.]+(?:\s*(?:m|metres|meters|metra|pcs|pieces|cope))?$/', $q) ? mb_substr($q, 0, 80) : null;
    }

    public static function identity(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        $text = preg_replace_callback('/\d+/', fn ($m) => (string) (int) $m[0], $text);
        return preg_replace('/[^a-z0-9]/', '', $text);
    }
}
