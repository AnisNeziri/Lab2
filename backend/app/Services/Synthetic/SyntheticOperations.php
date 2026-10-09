<?php

namespace App\Services\Synthetic;

use App\Models\{Customer, GoodsReceipt, Product, PurchaseOrder, SalesOrder, Shipment, WarehouseStock};
use App\Services\{CustomerDebtService, ExpenseService, FinancialAccountService, InventoryCountService, LandedCostService, OutboundService, ProcurementService, PurchaseOrderService, ShipmentIntelligenceService, WarehouseOperationsService};
use App\Support\Money;
use Illuminate\Support\Facades\Auth;

/** A chronological event agenda. Future plans are dates, never future actual transactions. */
class SyntheticOperations
{
    private array $receipts = [];
    private array $collections = [];
    private array $supplierPayments = [];
    private array $milestones = [];

    public function morning(SyntheticCompanyScenario $s, int $day): void
    {
        foreach ($this->milestones[$day] ?? [] as [$shipmentId, $type]) {
            $shipment = Shipment::findOrFail($shipmentId);
            $planned = $shipment->milestones()->where('milestone_type', $type)->first()?->planned_at?->toIso8601String();
            app(\App\Services\ControlTowerService::class)->upsertMilestone($shipment, ['milestone_type' => $type, 'status' => 'completed', 'planned_at' => $planned, 'actual_at' => now()->toIso8601String(), 'notes' => 'SYNTHETIC milestone recorded when it occurred.']);
            if ($type === 'supplier_dispatch') $shipment->update(['status' => 'in_transit', 'departed_at' => now()]);
            if ($type === 'warehouse_arrival') $shipment->update(['status' => 'delivered', 'arrival_date' => now()]);
            app(ShipmentIntelligenceService::class)->refresh($shipment->id);
        }
        foreach ($this->receipts[$day] ?? [] as [$orderId, $fraction]) {
            $po = PurchaseOrder::findOrFail($orderId)->load('items');
            if ($po->status === 'cancelled') continue;
            $items = $po->items->map(function ($i) use ($fraction) {
                $remaining = (float) $i->quantity - (float) $i->received_quantity;
                return ['id' => $i->id, 'quantity' => $fraction < 1 ? floor($remaining * $fraction) : $remaining];
            })->filter(fn ($i) => $i['quantity'] > 0)->values()->all();
            if (! $items) continue;
            app(PurchaseOrderService::class)->receive($po, ['items' => $items, 'received_at' => today()->toDateString(), 'idempotency_key' => $s->key('receipt')]);
            $receipt = GoodsReceipt::where('purchase_order_id', $po->id)->latest('id')->firstOrFail();
            if ($receipt->id % 3 === 0) {
                $quality = app(\App\Services\QualityManagementService::class);
                $item = $receipt->items()->firstOrFail();
                $inspection = $quality->createForReceiptItem($item, ['notes' => 'Synthetic incoming-goods check']);
                $damaged = $receipt->id % 6 === 0 ? 1 : 0;
                $quality->finalize($inspection, ['accepted_quantity' => (float) $inspection->received_quantity - $damaged, 'rejected_quantity' => 0, 'quarantine_quantity' => 0, 'damaged_quantity' => $damaged, 'notes' => 'Synthetic visual and dimensional inspection completed']);
            }
            foreach (['freight' => 70, 'customs' => 25, 'insurance' => 8, 'other' => 30] as $type => $amount) {
                $cost = app(LandedCostService::class)->createDraft(['goods_receipt_id' => $receipt->id, 'cost_type' => $type, 'amount' => $amount, 'currency' => 'EUR', 'allocation_method' => 'value', 'idempotency_key' => $s->key('landed-cost'), 'notes' => $type === 'other' ? 'Synthetic inland transport' : 'Synthetic '.$type]);
                app(LandedCostService::class)->post($cost);
            }
        }
        if ($day === 0) $this->initialTransfer($s);
        if ($day % 7 === 1) $this->replenish($s, $day);
        if ($day % 28 === 2) $this->expense($s, $day);
        if ($day % 14 === 4 && $day > 0) $this->rebalance($s, $day);
    }

    private function initialTransfer(SyntheticCompanyScenario $s): void
    {
        foreach (array_slice($s->products, 0, 6) as $row) {
            $p = $row['model']->fresh();
            $quantity = floor((float) $p->quantity * .35);
            $this->transfer($s, $p, $s->warehouses[0]->id, $s->warehouses[1]->id, $quantity);
        }
        if (count($s->warehouses) > 2) foreach (array_slice($s->products, 6, 3) as $row) $this->transfer($s, $row['model']->fresh(), $s->warehouses[0]->id, $s->warehouses[2]->id, $row['base'] * 3);
    }

    private function rebalance(SyntheticCompanyScenario $s, int $day): void
    {
        foreach (array_slice($s->products, 0, 3) as $row) {
            $p = $row['model']->fresh();
            $surplus = (float) WarehouseStock::where('product_id', $p->id)->where('warehouse_id', $s->warehouses[1]->id)->sum('available_quantity');
            $quantity = min(floor($surplus / 2), $row['base'] * 5);
            if ($quantity > 0) $this->transfer($s, $p, $s->warehouses[1]->id, $s->warehouses[0]->id, $quantity);
        }
    }

    private function transfer(SyntheticCompanyScenario $s, Product $p, int $from, int $to, float $quantity): void
    {
        $service = app(WarehouseOperationsService::class);
        $transfer = $service->createTransfer(['source_warehouse_id' => $from, 'destination_warehouse_id' => $to, 'destination_location_id' => $s->bins[$to][0]->id, 'notes' => 'Synthetic reserve-to-dispatch balancing', 'items' => [['product_id' => $p->id, 'quantity' => $quantity]]]);
        $transfer = $service->dispatch($transfer, $s->key('transfer-dispatch'));
        $service->receive($transfer, ['idempotency_key' => $s->key('transfer-receive'), 'items' => $transfer->items->map(fn ($i) => ['id' => $i->id, 'accepted_quantity' => $i->quantity, 'damaged_quantity' => 0])->all()]);
    }

    private function replenish(SyntheticCompanyScenario $s, int $day): void
    {
        $needed = array_values(array_filter($s->products, function ($row) use ($s, $day) {
            $p = $row['model']->fresh();
            // Hold off one final replenishment to leave an honest low-stock / imbalance case.
            if ($day > $s->config['days'] - 18 && $row['index'] % 9 === 0) return false;
            $incoming = \App\Models\PurchaseOrderItem::where('product_id', $p->id)->whereHas('purchaseOrder', fn ($q) => $q->whereNotIn('status', ['cancelled', 'completed']))->get()->sum(fn ($i) => max(0, (float) $i->quantity - (float) $i->received_quantity));
            return (float) $p->quantity + $incoming < $row['base'] * 23;
        }));
        $requests = [];
        foreach (\App\Models\PurchaseRequest::where('status', 'draft')->where('notes', 'like', 'Enterprise Decision V5%')->with('items')->get() as $request) $requests[] = $request;
        $linkedIds = collect($requests)->flatMap(fn ($r) => $r->items->pluck('product_id'))->all();
        $needed = array_values(array_filter($needed, fn ($r) => ! in_array($r['model']->id, $linkedIds)));
        foreach (array_chunk($needed, 4) as $batch) {
            $service = app(ProcurementService::class);
            $request = $service->createRequest(['requested_at' => today()->toDateString(), 'required_by' => today()->addDays(10)->toDateString(), 'currency' => 'EUR', 'notes' => 'Synthetic replenishment based on available and already incoming stock.', 'items' => array_map(fn ($r) => ['product_id' => $r['model']->id, 'description' => $r['model']->name, 'unit' => $r['model']->unit, 'quantity' => ceil($r['base'] * 32 / 10) * 10, 'estimated_unit_price' => $r['cost']], $batch)]);
            $requests[] = $request;
        }
        foreach ($requests as $batchIndex => $request) {
            $service = app(ProcurementService::class);
            $request = $service->submit($request);
            $rfq = $service->createRfq($request, ['supplier_ids' => array_map(fn ($v) => $v->id, $s->suppliers), 'response_due_at' => today()->addDays(2)->toDateString()]);
            $rfq = $service->issueRfq($rfq);
            $quotes = [];
            foreach ($s->suppliers as $index => $supplier) {
                $quotes[] = $service->createQuote($rfq, ['supplier_id' => $supplier->id, 'currency' => 'EUR', 'exchange_rate' => 1, 'quoted_at' => now(), 'valid_until' => today()->addDays(14)->toDateString(), 'items' => $request->items->map(fn ($item) => ['purchase_request_item_id' => $item->id, 'offered_quantity' => $item->quantity, 'unit_price' => round((float) $item->estimated_unit_price * [1, 1.08, .97, 1.18, 1.1, 1.05][$index], 2), 'lead_time_days' => [7, 5, 12, 3, 8, 6][$index], 'minimum_order_quantity' => min(5, (float) $item->quantity), 'order_multiple' => $item->unit === 'm' ? .001 : 1])->all()]);
            }
            $selected = [];
            foreach ($request->items as $line => $item) $selected[] = $quotes[(intdiv($day, 7) + $batchIndex + $line) % count($quotes)]->items->firstWhere('purchase_request_item_id', $item->id)->id;
            $rfq = $service->award($rfq, $selected);
            foreach ($service->convertAwards($rfq, $s->key('award'), $s->warehouses[0]->id) as $id) {
                $po = PurchaseOrder::findOrFail($id)->load('items');
                app(PurchaseOrderService::class)->changeStatus($po, 'ordered', 'Synthetic owner confirms supplier award.');
                if ($po->id % 17 === 0) { app(PurchaseOrderService::class)->cancel($po->fresh(), 'Synthetic supplier unable to confirm this unreceived, unpaid order.'); continue; }
                $vendor = array_search($po->supplier_id, array_map(fn ($v) => $v->id, $s->suppliers));
                $lead = [7, 5, 12, 3, 8, 6][$vendor];
                $delay = $vendor === 2 ? $s->sample('delay-'.$id, 0, 7) : ($vendor === 0 && $id % 5 === 0 ? 2 : 0);
                $arrival = $day + $lead + $delay;
                $partial = $id % 4 === 0;
                $this->receipts[$arrival][] = [$id, $partial ? .6 : 1];
                if ($partial) $this->receipts[$arrival + 3][] = [$id, 1];
                $this->supplierPayments[$day][] = [$id, .24];
                $this->supplierPayments[$day + 14][] = [$id, .4];
                if ($id % 3 !== 0) $this->supplierPayments[$day + 28][] = [$id, 1];
                $this->shipment($s, $po, $day, $lead, $arrival);
            }
        }
    }

    private function shipment(SyntheticCompanyScenario $s, PurchaseOrder $po, int $day, int $lead, int $arrival): void
    {
        $shipment = Shipment::create(['tracking_number' => 'SYN-CARGO-'.str_pad((string) $po->id, 5, '0', STR_PAD_LEFT), 'purchase_order_id' => $po->id, 'supplier_id' => $po->supplier_id, 'warehouse_id' => $po->warehouse_id, 'transport_mode' => 'sea', 'carrier' => 'Synthetic Carrier — NOT LIVE AIS', 'vessel_name' => 'Synthetic Supply Vessel', 'origin_port' => 'Istanbul', 'destination_port' => 'Durres', 'status' => 'planned', 'eta' => today()->addDays(max(1, $lead - 2)), 'tracking_provider' => 'synthetic', 'tracking_mode' => 'synthetic', 'is_saved' => true, 'notes' => 'SYNTHETIC / TEST shipment. No external provider, AIS position, or real vessel claimed.']);
        $shipment->purchaseOrders()->syncWithPivotValues([$po->id], ['company_id' => $po->company_id]);
        $types = ['supplier_dispatch' => 1, 'vessel_departure' => 2, 'destination_port' => max(2, $lead - 2), 'customs_started' => max(2, $lead - 2), 'customs_cleared' => max(2, $lead - 1), 'inland_departure' => max(2, $lead - 1), 'warehouse_arrival' => $lead];
        foreach ($types as $type => $offset) {
            app(\App\Services\ControlTowerService::class)->upsertMilestone($shipment, ['milestone_type' => $type, 'status' => 'planned', 'planned_at' => today()->addDays($offset)->toIso8601String(), 'actual_at' => null, 'notes' => 'SYNTHETIC planned milestone before outcome.']);
            $actualOffset = $type === 'warehouse_arrival' ? $arrival - $day : $offset + ($type === 'customs_cleared' || $type === 'inland_departure' ? max(0, $arrival - $day - $lead) : 0);
            $this->milestones[$day + $actualOffset][] = [$shipment->id, $type];
        }
        app(ShipmentIntelligenceService::class)->refresh($shipment->id); // Prediction freezes before actual arrival.
    }

    public function sales(SyntheticCompanyScenario $s, int $day): void
    {
        if (today()->dayOfWeekIso === 7) return;
        $service = app(OutboundService::class);
        $active = array_values(array_filter($s->customers, fn ($r) => ! ($r['segment'] === 'new' && $day < 60) && ! ($r['segment'] === 'inactive' && $day > 40) && ! ($r['segment'] === 'decline' && $day > 70 && $day % 4 !== 0)));
        $batches = array_fill(0, $s->config['order_intensity'], []);
        foreach ($s->products as $row) {
            $p = $row['model']->fresh();
            $factor = match ($row['profile']) {'growth' => .45 + $day / 65, 'decline' => max(.15, 1.5 - $day / 70), 'intermittent' => $day % 5 === 0 ? 2.5 : 0, 'slow' => $day % 9 === 0 ? 1 : 0, 'seasonal' => 1 + .45 * sin($day / 12), default => 1};
            $quantity = $row['base'] * $factor * ($s->sample('demand-'.$day.'-'.$p->id, 85, 115) / 100);
            $quantity = $p->unit === 'm' ? round($quantity, 2) : floor($quantity);
            if ($quantity <= 0) continue;
            $available = (float) WarehouseStock::where('product_id', $p->id)->where('warehouse_id', $s->warehouses[0]->id)->sum('available_quantity');
            if ($available < $quantity) { $s->notes['stockout_products'][$p->id] = $p->name; continue; }
            $unit = $p->unit; $price = $p->selling_price;
            if ($unit === 'pcs' && $day % 11 === 0 && $quantity >= 10) { $quantity = floor($quantity / 10); $unit = 'box'; $price = Money::multiply($price, 10); }
            $batches[$row['index'] % count($batches)][] = ['product_id' => $p->id, 'quantity' => $quantity, 'unit' => $unit, 'unit_price' => $price];
        }
        foreach ($batches as $index => $items) {
            if (! $items) continue;
            $weighted = [];
            foreach ($active as $candidate) {
                $weight = match ($candidate['segment']) {'frequent' => 3, 'growth' => 1 + intdiv($day, 30), 'decline' => max(1, 3 - intdiv($day, 30)), 'occasional' => 1, default => 2};
                for ($i = 0; $i < $weight; $i++) $weighted[] = $candidate;
            }
            $customer = $weighted[$s->sample('customer-'.$day.'-'.$index, 0, count($weighted) - 1)]['model']->fresh();
            if ($index === 0 && $day % 3 === 0) $customer = $s->customers[7]['model']->fresh();
            $cash = ($day + $index) % 4 === 0;
            $order = $service->create(['customer_id' => $customer->id, 'warehouse_id' => $s->warehouses[0]->id, 'order_date' => today()->toDateString(), 'requested_delivery_date' => today()->addDays(2)->toDateString(), 'currency' => 'EUR', 'payment_type' => $cash ? 'cash' : 'credit', 'idempotency_key' => $s->key('sales-order'), 'notes' => 'Synthetic recurring wholesale order', 'items' => $items]);
            app(\App\Services\OrderHubService::class)->attachManual($order, ['customer_id' => $customer->id, 'items' => $items, 'payment_type' => $cash ? 'cash' : 'credit']);
            if ($day % 19 === 8 && $index === 1) { $service->action($order, 'cancel', ['idempotency_key' => $s->key('cancel'), 'reason' => 'Synthetic customer cancelled before dispatch']); continue; }
            if ($day > $s->config['days'] - 4 && $index === 2) continue; // Genuine pending orders.
            foreach (['confirm', 'reserve', 'allocate'] as $action) $service->action($order->fresh(), $action, ['idempotency_key' => $s->key($action)]);
            $order = $order->fresh('allocations');
            $partial = $day % 23 === 10 && $index === 0;
            foreach ($order->allocations as $a) {
                $quantity = $partial ? floor((float) $a->quantity / 2) : $a->quantity;
                if ((float) $quantity <= 0) continue;
                $service->action($order, 'pick', ['allocation_id' => $a->id, 'location_id' => $a->location_id, 'inventory_lot_id' => $a->inventory_lot_id, 'quantity' => $quantity, 'manual_verification' => true, 'reason' => 'Synthetic verified warehouse picking', 'idempotency_key' => $s->key('pick')]);
            }
            $order = $order->fresh('allocations');
            $service->action($order, 'pack', ['items' => $order->allocations->filter(fn ($a) => (float) $a->picked_quantity > 0)->map(fn ($a) => ['allocation_id' => $a->id, 'quantity' => $a->picked_quantity])->values()->all(), 'idempotency_key' => $s->key('pack')]);
            $order = $order->fresh('packages');
            $service->action($order, 'dispatch', ['package_ids' => $order->packages->pluck('id')->all(), 'idempotency_key' => $s->key('dispatch')]);
            $order = $order->fresh('dispatches');
            $dispatch = $order->dispatches->last();
            if ($day % 12 === 2 && $index === 0) {
                $sale = \App\Models\DailySale::findOrFail($dispatch->daily_sale_id)->load('items');
                $invoice = app(\App\Services\InvoiceService::class)->createDraft(['customer_id' => $customer->id, 'invoice_date' => today()->toDateString(), 'supply_date' => today()->toDateString(), 'due_date' => today()->addDays($customer->payment_terms_days)->toDateString(), 'notes' => 'SYNTHETIC / TEST invoice documenting an existing dispatch — not another sale.', 'items' => $sale->items->map(fn ($i) => ['product_id' => $i->product_id, 'description' => $i->product_name, 'unit' => $i->unit, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price])->all()], $sale);
                app(\App\Services\InvoiceService::class)->issue($invoice);
            }
            if (! $partial) $service->action($order, 'delivery', ['dispatch_id' => $dispatch->id, 'recipient' => $customer->name, 'idempotency_key' => $s->key('delivery')]);
            if (! $cash) {
                $this->collections[$day + 7 + $customer->id % 8][] = [$customer->id, Money::multiply($dispatch->total_amount, .4)];
                if ($customer->id % 6 !== 0) $this->collections[$day + 20 + $customer->id % 10][] = [$customer->id, Money::multiply($dispatch->total_amount, .6)];
            } else {
                app(FinancialAccountService::class)->post($s->accountId, ['type' => 'inflow', 'amount' => $dispatch->total_amount, 'transaction_date' => today()->toDateString(), 'source_type' => 'daily_sale', 'source_id' => $dispatch->daily_sale_id, 'counter_accounting_account_id' => \App\Models\AccountingAccount::where('code', '1000')->value('id'), 'counterparty' => $customer->name, 'description' => 'Synthetic daily cash takings deposited to operating bank', 'idempotency_key' => $s->key('cash-sale')]);
            }
        }
    }

    public function payments(SyntheticCompanyScenario $s, int $day): void
    {
        foreach ($this->collections[$day] ?? [] as [$id, $amount]) if (Money::compare($amount, 0) > 0) app(CustomerDebtService::class)->recordPayment(Customer::findOrFail($id), ['amount' => $amount, 'transaction_date' => today()->toDateString(), 'financial_account_id' => $s->accountId, 'payment_method' => 'bank_transfer', 'reference_number' => 'SYN-COLLECTION-'.$day.'-'.$id, 'note' => 'Synthetic scheduled customer collection', 'idempotency_key' => $s->key('collection')]);
        if ($day % 30 === 3) app(CustomerDebtService::class)->recordPayment($s->customers[2]['model']->fresh(), ['amount' => '1200.00', 'transaction_date' => today()->toDateString(), 'financial_account_id' => $s->accountId, 'payment_method' => 'bank_transfer', 'note' => 'Synthetic customer advance for later deliveries', 'idempotency_key' => $s->key('advance')]);
        foreach ($this->supplierPayments[$day] ?? [] as [$id, $fraction]) {
            $po = PurchaseOrder::findOrFail($id);
            $amount = $fraction === 1 ? $po->remaining_balance : min((float) $po->remaining_balance, (float) Money::multiply($po->total_amount, $fraction));
            if (Money::compare($amount, 0) > 0) app(PurchaseOrderService::class)->pay($po, ['amount' => $amount, 'payment_date' => today()->toDateString(), 'payment_method' => 'bank_transfer', 'financial_account_id' => $s->accountId, 'idempotency_key' => $s->key('supplier-payment'), 'note' => 'Synthetic supplier staged payment']);
        }
    }

    public function count(SyntheticCompanyScenario $s, int $day): void
    {
        $service = app(InventoryCountService::class);
        $session = $service->create(['warehouse_id' => $s->warehouses[0]->id, 'product_ids' => array_map(fn ($r) => $r['model']->id, array_slice($s->products, 0, 4)), 'stock_states' => ['available'], 'notes' => 'Synthetic cycle count — small verified variance']);
        $items = $session->items->map(fn ($i, $n) => ['count_item_id' => $i->id, 'counted_quantity' => max(0, (float) $i->expected_quantity - ($n === 0 ? 1 : 0)), 'notes' => 'Verified synthetic physical count'])->all();
        $session = $service->record($session, ['items' => $items]);
        $session = $service->requestRecount($session, [$session->items->first()->id], 'Independent check of the one-unit variance');
        $session = $service->record($session, ['items' => $items]);
        $session = $service->submit($session);
        Auth::setUser($s->manager);
        try { $service->approve($session, 'Synthetic independent recount confirms variance'); }
        finally { Auth::setUser($s->owner); }
    }

    private function expense(SyntheticCompanyScenario $s, int $day): void
    {
        $service = app(ExpenseService::class);
        foreach (['rent' => 1800, 'utilities' => 420, 'transport' => 260] as $category => $amount) {
            $expense = $service->create(['vendor_name' => 'Synthetic '.$category.' provider', 'document_type' => 'purchase_invoice', 'document_number' => 'SYN-EXP-'.$category.'-'.$day, 'invoice_date' => today()->toDateString(), 'received_date' => today()->toDateString(), 'supply_date' => today()->toDateString(), 'due_date' => today()->addDays(7)->toDateString(), 'currency' => 'EUR', 'net_amount' => $amount, 'vat_amount' => 0, 'vat_rate' => 0, 'vat_treatment' => 'non_vat', 'input_vat_eligible' => false, 'category' => 'other', 'source_type' => 'domestic', 'asset_treatment' => 'ordinary', 'description' => 'Synthetic '.$category.' operating cost']);
            $expense = $service->post($expense);
            $service->recordPayment($expense, ['amount' => $amount, 'payment_date' => today()->toDateString(), 'payment_method' => 'bank_transfer', 'financial_account_id' => $s->accountId, 'idempotency_key' => $s->key('expense-payment')]);
        }
    }
}
