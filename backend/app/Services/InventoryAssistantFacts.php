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
    public function read(string $tool): array
    {
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
        $ids = PurchaseOrderItem::query()->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', ['confirmed', 'ordered', 'partially_received'])
            ->whereBetween('expected_at', [$today, $end]))->whereNotNull('product_id')->select('product_id')->distinct()->orderBy('product_id')->limit(26)->pluck('product_id');
        $products = Product::whereIn('id', $ids->take(25))->orderBy('id')->get(['id', 'company_id', 'name', 'unit', 'quantity']);
        $snapshots = app(InventorySnapshotService::class)->forProducts($products);
        $rows = [];
        foreach ($products as $product) {
            foreach ($snapshots[$product->id]['incoming_schedule'] as $line) {
                if (! $line['expected_at'] || $line['expected_at'] < $today || $line['expected_at'] > $end) {
                    continue;
                }
                $rows[] = ['name' => $product->name, 'quantity' => $line['quantity'], 'unit' => $product->unit, 'expected_at' => $line['expected_at'],
                    'reference' => 'PO #'.$line['purchase_order_id'], 'url' => '/purchase-orders?po='.$line['purchase_order_id']];
            }
        }

        return ['as_of' => now()->toIso8601String(), 'through' => $end, 'rows' => array_slice($rows, 0, 25), 'truncated' => $ids->count() > 25 || count($rows) > 25];
    }

    public function reply(string $intent, string $conversation, bool $sq): array
    {
        $tool = match ($intent) {
            'inventory_health' => 'get_inventory_health','stock_commitments' => 'get_stock_commitments',default => 'get_incoming_supply'
        };
        $runner = app(AssistantToolRunner::class);
        $data = $runner->execute($tool);
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
                ($sq ? 'Furnizim i pritshëm gjatë 7 ditëve të ardhshme, sipas PO-ve me data të njohura dhe afateve të ngarkesave.' : 'Supply expected over the next 7 days, from dated POs and adjusted cargo estimates.');
            if (! $cards) {
                $text .= $sq ? ' Nuk ka rreshta të kualifikuar në këtë pamje.' : ' No qualifying rows in this bounded view.';
            }
        }

        return ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'intent' => $intent, 'text' => $text, 'cards' => $cards,
            'sources' => [['tool' => $tool, 'url' => '/warehouse-operations?tab=control', 'as_of' => $data['as_of'] ?? $data['generated_at']]],
            'limitations' => [$sq ? 'Pamje e kufizuar; hapni burimin për të gjitha dokumentet. Datat janë vlerësime. Stoku pa sasi bazë ose datë të verifikuar nuk premtohet. Veprimet kërkojnë përdorues të autorizuar.' : 'Bounded preview; open the source for all records. Dates are estimates. Undated supply or unknown base quantities are not promised. Stock actions require an authorized user.'],
            'choices' => [], 'follow_ups' => [], 'context' => null, 'context_entities' => [], 'read_only' => true, 'provider' => 'deterministic', 'state' => 'success', 'is_prediction' => false, 'tools' => $runner->executions];
    }
}
