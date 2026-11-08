<?php

namespace App\Services;

use App\Models\OutboundAllocation;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Support\CompanyClock;
use Illuminate\Support\Str;

/** Bounded read-only answers, backed by the existing inventory authorities. */
class InventoryAssistantFacts
{
    public function read(string $tool, array $input=[]): array
    {
        if($tool==='get_inventory_unit_totals'){
            $v=validator($input,['unit'=>'required|string|max:20'])->validate();$available=0;$count=0;
            Product::where('unit',$v['unit'])->withStockTotals()->chunkById(250,function($rows)use(&$available,&$count){foreach($rows as $product){$available+=(float)$product->available_quantity;$count++;}});
            return ['unit'=>$v['unit'],'available'=>round($available,3),'products'=>$count,'as_of'=>now()->toIso8601String()];
        }
        if ($tool === 'get_inventory_health') {
            return app(InventoryIntegrityService::class)->operationalHealth();
        }
        if ($tool === 'get_stock_commitments') {
            $query = OutboundAllocation::query()->where('status', '!=', 'released')->whereRaw('quantity > dispatched_quantity');

            return ['as_of' => now()->toIso8601String(), 'count' => (clone $query)->count(), 'rows' => $query
                ->with(['item.product:id,name,unit', 'item.order:id,order_number', 'warehouse:id,name'])
                ->orderBy('id')->limit(25)->get()->map(fn ($row) => [
                    'name' => $row->item?->product?->name, 'reference' => $row->item?->order?->order_number,
                    'quantity' => round((float) $row->quantity - (float) $row->dispatched_quantity, 3), 'unit' => $row->item?->product?->unit,
                    'warehouse' => $row->warehouse?->name, 'kind' => $row->pick_task_id ? 'picking' : 'reservation',
                    'url' => '/fulfillment?order='.$row->sales_order_id,
                ])->all()];
        }
        // Candidate selection is bounded; final quantities and adjusted dates come
        // from the same PO/cargo/receipt snapshot used by Sales and Warehouse.
        $today = CompanyClock::today()->toDateString();
        $end = CompanyClock::today()->addDays(7)->toDateString();
        $overdue=($input['window']??'upcoming')==='overdue';
        if (! $overdue && isset($input['through'])) {
            validator($input, ['through' => 'date_format:Y-m-d|after_or_equal:'.$today.'|before_or_equal:'.$end])->validate();
            $end = $input['through'];
        }
        $ids = PurchaseOrderItem::query()->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', ['confirmed', 'ordered', 'partially_received'])
            ->when($overdue,fn($q)=>$q->whereDate('expected_at','<',$today),fn($q)=>$q->whereBetween('expected_at', [$today, $end])))->whereNotNull('product_id')->select('product_id')->distinct()->orderBy('product_id')->limit(26)->pluck('product_id');
        $products = Product::whereIn('id', $ids->take(25))->orderBy('id')->get(['id', 'company_id', 'name', 'unit', 'quantity']);
        $snapshots = app(InventorySnapshotService::class)->forProducts($products);
        $rows = [];
        foreach ($products as $product) {
            foreach ($snapshots[$product->id]['incoming_schedule'] as $line) {
                if (! $line['expected_at'] || ($overdue?$line['expected_at'] >= $today:($line['expected_at'] < $today || $line['expected_at'] > $end))) {
                    continue;
                }
                $rows[] = ['name' => $product->name, 'quantity' => $line['quantity'], 'unit' => $product->unit, 'expected_at' => $line['expected_at'],
                    'reference' => 'PO #'.$line['purchase_order_id'], 'url' => '/purchase-orders?po='.$line['purchase_order_id']];
            }
        }

        return ['as_of' => now()->toIso8601String(), 'window'=>$overdue?'overdue':'upcoming','through' => $overdue?$today:$end, 'rows' => array_slice($rows, 0, 25), 'truncated' => $ids->count() > 25 || count($rows) > 25];
    }

    public function reply(string $intent, string $conversation, bool $sq, array $input=[]): array
    {
        if($intent==='inventory_totals'){
            $runner=app(AssistantToolRunner::class);$data=$runner->execute('get_inventory_unit_totals',$input);
            return ['id'=>(string)Str::uuid(),'conversation_id'=>$conversation,'intent'=>$intent,'text'=>($sq?'Stoku i disponueshëm tani: ':'Available stock now: ').$data['available'].' '.$data['unit'],'cards'=>[['tool'=>'get_inventory_unit_totals','title'=>$sq?'Stoku aktual':'Current inventory','url'=>'/warehouse-operations','metrics'=>[['label'=>$sq?'Në dispozicion':'Available','value'=>$data['available'],'unit'=>$data['unit']]],'evidence'=>$data]],'sources'=>[['tool'=>'get_inventory_unit_totals','url'=>'/warehouse-operations']],'limitations'=>[$sq?'Vetëm produktet me këtë njësi; sasitë me njësi të ndryshme nuk mblidhen.':'Only products using this unit; different inventory units are never added together.'],'read_only'=>true,'state'=>$data['products']?'success':'insufficient','provider'=>'deterministic','tools'=>$runner->executions];
        }
        $tool = match ($intent) {
            'inventory_health' => 'get_inventory_health','stock_commitments' => 'get_stock_commitments',default => 'get_incoming_supply'
        };
        $runner = app(AssistantToolRunner::class);
        $data = $runner->execute($tool, $intent === 'incoming_supply' ? $input : []);
        $cards = [];
        if ($intent === 'inventory_health') {
            $names = ['integrity' => ['Inconsistent stock', 'Stok jokonsistent'], 'quality_hold' => ['Quality holds', 'Kontrolle cilësie'],
                'damaged_blocked' => ['Damaged / blocked', 'Dëmtuar / bllokuar'], 'overdue_receipts' => ['Overdue receipts', 'Pranime të vonuara'],
                'stale_transfers' => ['Transfers open over 7 days', 'Transfere mbi 7 ditë'], 'count_variances' => ['Count variances', 'Diferenca numërimi'],
                'reservation_conflicts' => ['Invalid lot reservations', 'Rezervime lotesh të pavlefshme']];
            foreach ($data['checks'] as $key => $check) {
                if ($check['count'] > 0) {
                    $cards[] = ['title' => $names[$key][$sq ? 1 : 0], 'tool' => $tool,
                        'url' => '/warehouse-operations?tab=control', 'status' => $check['status'], 'metrics' => [['label' => $sq ? 'Për shqyrtim' : 'Review', 'value' => $check['count'], 'unit' => '']]];
                }
            }
            $text = $cards ? ($sq ? 'Këto fakte të inventarit kërkojnë shqyrtim në burim.' : 'These current inventory facts need source review.') : ($sq ? 'Kontrollet e dukshme nuk kanë përjashtime.' : 'The visible operational checks have no exceptions.');
        } else {
            foreach ($data['rows'] as $row) {
                $cards[] = ['tool' => $tool, 'title' => $row['name'], 'url' => $row['url'],
                    'summary' => implode(' · ', array_filter([$row['reference'], $row['warehouse'] ?? null, $row['expected_at'] ?? null])),
                    'metrics' => [['label' => $sq ? 'Sasia' : 'Quantity', 'value' => $row['quantity'], 'unit' => $row['unit']]]];
            }
            $text = $intent === 'stock_commitments' ? ($sq ? 'Rezervimet dhe alokimet aktive të klientëve. Mbledhja përfshihet në rezervime.' : 'Active customer reservations and picking allocations. Picking is included in reserved stock.') :
                (isset($input['through'])
                    ? ($sq ? 'Furnizimi i pritshëm deri më ' : 'Supply expected through ').$data['through'].'.'
                    : ($sq ? 'Furnizim i pritshëm gjatë 7 ditëve të ardhshme, sipas PO-ve me data të njohura dhe afateve të ngarkesave.' : 'Supply expected over the next 7 days, from dated POs and adjusted cargo estimates.'));
            if (! $cards) {
                $text .= $sq ? ' Nuk ka rreshta të kualifikuar në këtë pamje.' : ' No qualifying rows in this bounded view.';
            }
        }

        $url = $intent === 'incoming_supply' ? '/purchase-orders' : '/warehouse-operations?tab=control';
        return ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => $intent, 'text' => $text, 'cards' => $cards,
            'sources' => [['tool' => $tool, 'url' => $url, 'as_of' => $data['as_of'] ?? $data['generated_at']]],
            'next_action' => $intent === 'incoming_supply' ? ['url' => $cards[0]['url'] ?? $url, 'label' => $sq ? 'Shqyrto afatet e pranimit' : 'Review receipt dates'] : null,
            'limitations' => [$sq ? 'Pamje e kufizuar; hapni burimin për të gjitha dokumentet. Datat janë vlerësime. Stoku pa sasi bazë ose datë të verifikuar nuk premtohet. Veprimet kërkojnë përdorues të autorizuar.' : 'Bounded preview; open the source for all records. Dates are estimates. Undated supply or unknown base quantities are not promised. Stock actions require an authorized user.'],
            'choices' => [], 'follow_ups' => [], 'context' => null, 'context_entities' => [], 'read_only' => true, 'provider' => 'deterministic', 'state' => 'success', 'is_prediction' => false, 'tools' => $runner->executions];
    }
}
