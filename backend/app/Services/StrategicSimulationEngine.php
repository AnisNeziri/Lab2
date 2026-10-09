<?php

namespace App\Services;

use App\Support\Money;
use Carbon\CarbonImmutable as Date;
use Illuminate\Validation\ValidationException;

/** Pure coordinated scenario world. No model, database, event or cache writes. */
final class StrategicSimulationEngine
{
    public const VERSION = 'strategic-simulation-v12.1';

    public function calculate(array $baseline, array $assumptions, bool $optimize = true): array
    {
        $started = microtime(true);
        $warnings = $baseline['limitations'] ?? [];
        $base = $this->world($baseline, [], $warnings);
        $world = $this->world($baseline, $assumptions, $warnings);
        $b = $this->project($base);
        $s = $this->project($world);
        $alternatives = $optimize ? $this->responses($world, $warnings) : [];
        $impact = ['stockout_exposures' => $s['summary']['stockout_exposures'] - $b['summary']['stockout_exposures'], 'below_target_scopes' => $s['summary']['below_target_scopes'] - $b['summary']['below_target_scopes'], 'money' => []];
        foreach (['purchase_requirement_by_currency', 'inventory_value_by_currency', 'required_inventory_capital_by_currency'] as $metric) {
            foreach (array_unique(array_merge(array_keys($b['summary'][$metric]), array_keys($s['summary'][$metric]))) as $c) {
                $complete = ! ($s['summary']['unknown_price_scopes'][$metric] ?? 0) && ! ($b['summary']['unknown_price_scopes'][$metric] ?? 0);
                $impact['money'][$metric][$c] = $complete && isset($s['summary'][$metric][$c], $b['summary'][$metric][$c]) ? Money::subtract($s['summary'][$metric][$c], $b['summary'][$metric][$c]) : null;
            }
        }
        $bottlenecks = $this->bottlenecks($world, $s, $alternatives);

        return ['engine_version' => self::VERSION, 'baseline_at' => $baseline['cutoff'], 'baseline' => $b, 'scenario' => $s, 'alternatives' => $alternatives, 'impact' => $impact, 'bottlenecks' => $bottlenecks,
            'confidence' => ['state' => $s['summary']['unsupported_scopes'] ? 'limited' : (($baseline['strategic_horizon'] ?? 90) > 90 ? 'limited' : 'moderate'), 'strong' => ['Frozen canonical stock, actual supplier/PO/shipment references and recorded obligations.'], 'limited' => array_values(array_unique($warnings))],
            'assumptions' => $assumptions, 'known_predicted' => $baseline['known_predicted'] ?? [], 'versions' => $baseline['versions'] ?? [], 'source_refs' => $baseline['source_refs'] ?? [],
            'limitations' => array_values(array_unique($warnings)), 'dependency_graph' => ['Demand/customer changes → one demand stream', 'Supplier/logistics changes → existing dated receipts', 'Dated receipts + reservations + demand → inventory exposure', 'Inventory exposure → V11 purchase/transfer response', 'Response commitments + dated obligations → V7 hypothetical cash consequences'],
            'stochastic' => ['state' => 'unavailable', 'reason' => 'Joint calibrated demand, lead-time and payment distributions are not established. No guessed Monte Carlo distribution is generated.'],
            'performance' => ['seconds' => round(microtime(true) - $started, 3), 'scope_count' => count($world['rows']), 'horizon' => $world['strategic_horizon'], 'response_horizon' => min(90, $world['strategic_horizon']), 'qualification' => 'Bounded local deterministic calculation; solver optimality applies only to the frozen V11 candidate set.'], 'read_only' => true];
    }

    private function world(array $baseline, array $assumptions, array &$warnings): array
    {
        $d = $baseline;
        $h = (int) ($d['strategic_horizon'] ?? $d['scope']['horizon']);
        $start = Date::parse($d['as_of'] ?? substr($d['cutoff'], 0, 10));
        $d['as_of'] = $start->toDateString();
        $d['scope']['horizon'] = min(90, $h);
        $d['financial_assumptions'] = array_values(array_filter($assumptions, fn ($a) => $a['type'] === 'financial' || $a['type'] === 'customer' && $a['action'] === 'payment_delay'));
        $d['arrival_shifts'] = [];
        $d['donors'] ??= [];
        foreach ($d['rows'] as &$row) {
            $i = &$row['input'];
            $i['as_of'] = $d['as_of'];
            $daily = $i['daily'];
            if ($h > count($daily) && $daily && ($d['scope']['long_horizon_mode'] ?? 'no_extension') === 'repeat_pattern') {
                $original = $daily;
                for ($n = count($daily); $n < $h; $n++) {
                    $daily[] = ['date' => $start->addDays($n)->toDateString(), 'quantity' => $original[$n % count($original)]['quantity']];
                }$warnings[] = 'Long-range demand repeats the frozen forecast pattern only with explicit user acknowledgment; it is a strategic assumption, not a precise forecast.';
            }
            $i['daily'] = array_slice($daily, 0, $h);
            $i['policy']['review_days'] = max(1, min($h, count($i['daily'])) - (int) ($i['supplier']['usual_lead_time_days'] ?? 0) - 1);
            $global = [];
            $customer = [];
            foreach ($assumptions as $a) {
                if (! $this->matches($a, $row)) {
                    continue;
                }$type = $a['type'];
                $action = $a['action'];
                $value = (float) ($a['value'] ?? 0);
                $days = (int) ($a['days'] ?? 0);
                if ($type === 'demand') {
                    $global[] = $a;
                }
                if ($type === 'customer' && in_array($action, ['percent', 'inactive', 'additional_quantity'])) {
                    $customer[] = $a;
                }
                if ($type === 'inventory') {
                    if ($action === 'safety_percent') {
                        $i['minimum_safety'] = max(0, $i['minimum_safety'] * (1 + $value / 100));
                    }if ($action === 'service_level') {
                        $i['policy']['service_level'] = $value;
                    }if ($action === 'reduction_percent') {
                        $i['stock']['available'] *= 1 - $value / 100;
                        $i['stock']['available_to_promise'] = max(0, $i['stock']['available'] - ($i['stock']['committed_outgoing'] ?? 0));
                    }if ($action === 'extra_cover_days' && $i['daily']) {
                        $i['minimum_safety'] += array_sum(array_column($i['daily'], 'quantity')) / count($i['daily']) * $days;
                    }
                }
                if ($type === 'warehouse' && $action === 'unavailable') {
                    $from = $start->addDays($a['start_day'] ?? 0)->toDateString();
                    $until = $start->addDays(($a['start_day'] ?? 0) + $days)->toDateString();
                    $q = $i['stock']['available'];
                    $i['simulation_unavailable_until'] = $until;
                    $i['stock']['available'] = 0;
                    $i['stock']['available_to_promise'] = 0;
                    if ($until <= $start->addDays($h)->toDateString()) {
                        $i['firm_incoming'][] = ['expected_at' => $until, 'quantity' => $q, 'source' => 'hypothetical_warehouse_reopening'];
                    }$warnings[] = 'Warehouse closure models temporarily unavailable supply, not destruction of owned inventory.';
                }
                if ($type === 'supplier' || ($type === 'procurement' && $action === 'restrict_supplier')) {
                    foreach ($row['suppliers'] as &$sup) {
                        if ($sup['supplier_id'] === $a['supplier_id']) {
                            if ($action === 'lead_days') {
                                $sup['usual_lead_time_days'] = max(0, (float) ($sup['usual_lead_time_days'] ?? 0) + $days);
                                if (isset($sup['lead_evidence']['p90_days'])) {
                                    $sup['lead_evidence']['p90_days'] = max(0, $sup['lead_evidence']['p90_days'] + $days);
                                }
                            }
                            if ($action === 'unavailable') {
                                $sup['usual_lead_time_days'] = (float) ($sup['usual_lead_time_days'] ?? 0) + $days;
                                $sup['lead_evidence']['eligible'] = false;
                            }
                            if ($action === 'price_percent' && $sup['purchase_price'] !== null) {
                                $sup['purchase_price'] = Money::multiply($sup['purchase_price'], 1 + $value / 100, 6);
                            }
                            if ($action === 'moq') {
                                $sup['minimum_order_quantity'] = $value;
                            }
                        }
                    }unset($sup);
                    if (in_array($action, ['remove', 'restrict_supplier']) || ($action === 'unavailable' && $days >= $h)) {
                        $row['suppliers'] = array_values(array_filter($row['suppliers'], fn ($sup) => $sup['supplier_id'] !== $a['supplier_id']));
                    }
                    if ($action === 'risk_percent') {
                        foreach ($row['supplier_risk'] as &$risk) {
                            if (($risk['supplier_id'] ?? null) === $a['supplier_id']) {
                                if (isset($risk['late_delivery_percent'])) {
                                    $risk['late_delivery_percent'] = max(0, min(100, $risk['late_delivery_percent'] + $value));
                                } else {
                                    $warnings[] = 'Supplier reliability lacks recorded baseline; no probability was fabricated.';
                                }
                            }
                        }unset($risk);
                    }
                }
            }
            foreach ($i['daily'] as $n => &$day) {
                $q = (float) $day['quantity'];
                $factor = 1;
                foreach ($global as $a) {
                    if ($this->active($a, $n)) {
                        $factor *= 1 + $a['value'] / 100;
                    }
                }$adjust = 0;
                $extra = 0;
                foreach ($customer as $a) {
                    $shares = collect($baseline['customer_shares'] ?? [])->filter(fn ($r) => $r['customer_id'] === $a['customer_id'] && $r['product_id'] === $row['product']['id']);
                    $warehouseId = $row['warehouse']['id'] ?? null;
                    $share = $warehouseId ? $shares->first(fn ($r) => ($r['warehouse_id'] ?? null) === $warehouseId) : null;
                    if (! $share) {
                        $share = $shares->first(fn ($r) => ! isset($r['warehouse_id']));
                        if ($warehouseId && $share) {
                            $warnings[] = 'Warehouse-specific customer demand share is unavailable; the frozen company share is an explicit distribution assumption for that scope.';
                        }
                    }if ($a['action'] === 'additional_quantity') {
                        if ($n === ($a['start_day'] ?? 0)) {
                            $extra += $a['value'];
                        }

                        continue;
                    }if (! $share) {
                        $warnings[] = 'Customer demand share is unavailable for a selected product; percentage change is not invented.';

                        continue;
                    }if ($this->active($a, $n) && ($a['action'] !== 'inactive' || $n < ($a['start_day'] ?? 0) + $a['days'])) {
                        $adjust += $share['share'] * ($a['action'] === 'inactive' ? -1 : $a['value'] / 100);
                    }
                }
                $day['quantity'] = round(max(0, $q * $factor * (1 + $adjust) + $extra), 6);
            }unset($day);
            foreach ($i['firm_incoming'] as &$incoming) {
                $shift = 0;
                $po = $incoming['purchase_order_id'] ?? null;
                $source = $baseline['purchase_order_sources'][$po] ?? [];
                foreach ($assumptions as $a) {
                    if (! $this->matches($a, $row)) {
                        continue;
                    }if ($a['type'] === 'supplier' && ($source['supplier_id'] ?? null) === $a['supplier_id'] && in_array($a['action'], ['lead_days', 'unavailable'])) {
                        $shift += $a['days'];
                    }if ($a['type'] === 'logistics' && $this->shipmentMatch($a, $po, $baseline)) {
                        $shift += $a['days'];
                    }
                }if (isset($incoming['expected_at'])) {
                    $originalArrival = $incoming['expected_at'];
                    $incoming['expected_at'] = max($d['as_of'], Date::parse($originalArrival)->addDays($shift)->toDateString(), $i['simulation_unavailable_until'] ?? $d['as_of']);
                    $shift = (int) Date::parse($originalArrival)->diffInDays(Date::parse($incoming['expected_at']), false);
                    if ($po) {
                        $d['arrival_shifts'][$po] = max($d['arrival_shifts'][$po] ?? $shift, $shift);
                    }
                }
            }unset($incoming);
            $oldSupplier = $i['supplier'];
            $oldLead = $i['lead'];
            $i['suppliers'] = $row['suppliers'];
            $i['supplier'] = $row['suppliers'][0] ?? [];
            $i['lead'] = $i['supplier'] === $oldSupplier ? $oldLead : [];
            if ($i['supplier']['lead_evidence']['eligible'] ?? false) {
                $i['lead'] = ['mean' => $i['supplier']['lead_evidence']['p90_days'], 'p90' => $i['supplier']['lead_evidence']['p90_days']];
            }
        }unset($i,$row);
        foreach ($assumptions as $a) {
            if ($a['type'] === 'procurement') {
                if ($a['action'] === 'commitment_limit') {
                    $d['scope']['commitment_limit'] = Money::normalize($a['value']);
                }if ($a['action'] === 'purchase_delay') {
                    $d['scope']['purchase_delay_days'] = $a['days'];
                }if ($a['action'] === 'replenishment_multiplier') {
                    $d['policy']['quantity_multiplier'] = $a['value'];
                }if ($a['action'] === 'allow_split') {
                    $d['allow_split'] = (bool) $a['value'];
                }
            }
            if ($a['type'] === 'warehouse' && $a['action'] === 'rebalance') {
                $source = null;
                $dest = null;
                foreach ($d['rows'] as $n => $r) {
                    if ($r['product']['id'] === $a['product_id']) {
                        if (($r['warehouse']['id'] ?? null) === $a['source_warehouse_id']) {
                            $source = $n;
                        }if (($r['warehouse']['id'] ?? null) === $a['warehouse_id']) {
                            $dest = $n;
                        }
                    }
                }
                if ($source === null || $dest === null) {
                    throw ValidationException::withMessages(['assumptions' => ['Rebalancing requires the product and both warehouses in scope.']]);
                }$q = (float) $a['value'];
                if ($q > $d['rows'][$source]['input']['stock']['available_to_promise'] + .0005) {
                    throw ValidationException::withMessages(['assumptions' => ['Hypothetical transfer exceeds frozen uncommitted source stock.']]);
                }$days = $d['scope']['transfer_lead_days'] ?? ($baseline['transfer_routes'][$a['source_warehouse_id'].':'.$a['warehouse_id']] ?? null);
                if ($days === null) {
                    throw ValidationException::withMessages(['assumptions' => ['Declare transit days when no qualified warehouse transfer history is recorded.']]);
                }$d['rows'][$source]['input']['stock']['available'] -= $q;
                $d['rows'][$source]['input']['stock']['available_to_promise'] -= $q;
                $arrival = max($start->addDays($days)->toDateString(), $d['rows'][$dest]['input']['simulation_unavailable_until'] ?? $d['as_of']);
                $d['rows'][$dest]['input']['firm_incoming'][] = ['expected_at' => $arrival, 'quantity' => $q, 'source' => 'hypothetical_rebalance'];
                $warnings[] = 'Rebalancing reports physical quantity; transfer cost is unknown, not zero.';
            }
        }
        // Donor stock must retain changed demand/safety, including selected source scopes.
        foreach ($d['donors'] as $key => &$donor) {
            $matching = collect($d['rows'])->firstWhere('key', $key);
            if ($matching) {
                $donor['input'] = $matching['input'];
            } else {
                $small = $baseline;
                $small['rows'] = [['key' => $key, 'input' => $donor['input'], 'product' => $donor['input']['product'], 'warehouse' => $donor['input']['warehouse'], 'suppliers' => $donor['input']['suppliers'], 'supplier_risk' => [], 'confidence' => 'limited']];
                $small['donors'] = [];
                $small['resources'] = [];
                $donorWorld = $this->world($small, array_values(array_filter($assumptions, fn ($a) => $a['type'] !== 'warehouse' || $a['action'] !== 'rebalance')), $warnings);
                $donor['input'] = $donorWorld['rows'][0]['input'];
            }
            $plan = app(InventoryPlanningMath::class)->calculate($donor['input'], ['base_quantity' => 0]);
            $path = array_slice($plan['timeline'], 0, min(90, $h));
            $qualified = $donor['input']['scope'] === 'qualified_warehouse' && $plan['quality']['qualified'] && count($path) >= min(90, $h);
            $surplus = $qualified ? max(0, min($donor['input']['stock']['available_to_promise'], min(array_column($path, 'baseline')) - $plan['safety_stock'])) : 0;
            $donor['quantity'] = $donor['input']['base_fractional'] ? floor($surplus * 1000) / 1000 : floor($surplus);
            $d['resources'][$key] = (int) round($donor['quantity'] * 1000);
        }unset($donor);
        foreach ($d['rows'] as &$r) {
            $r['transfers'] ??= [];
            foreach ($r['transfers'] as &$t) {
                $t['available'] = min($t['available'], ($d['resources'][$t['resource']] ?? 0) / 1000);
            }unset($t);
        }unset($r);
        if ($h > 90) {
            $warnings[] = 'V11 optimized response covers the first 90 days only. Longer strategic exposure remains visible and is not claimed covered.';
        }

        return $d;
    }

    private function active(array $a, int $day): bool
    {
        return $day >= ($a['start_day'] ?? 0) && $day <= ($a['end_day'] ?? 364);
    }

    private function matches(array $a, array $row): bool
    {
        return (! isset($a['product_id']) || $a['product_id'] === $row['product']['id']) && (! isset($a['category_id']) || $a['category_id'] === ($row['product']['category_id'] ?? null)) && (! isset($a['warehouse_id']) || $a['warehouse_id'] === ($row['warehouse']['id'] ?? null));
    }

    private function shipmentMatch(array $a, ?int $po, array $baseline): bool
    {
        if (! $po) {
            return false;
        }if (! isset($a['shipment_id']) && ! isset($a['route_from'])) {
            return true;
        }foreach ($baseline['shipments'] ?? [] as $s) {
            if (in_array($po, $s['purchase_order_ids'], true) && (! isset($a['shipment_id']) || $s['id'] === $a['shipment_id']) && (! isset($a['route_from']) || (str_contains(strtolower($s['origin'] ?? ''), strtolower($a['route_from'])) && str_contains(strtolower($s['destination'] ?? ''), strtolower($a['route_to']))))) {
                return true;
            }
        }

        return false;
    }

    private function project(array $world, array $lines = []): array
    {
        $summary = ['stockout_exposures' => 0, 'below_target_scopes' => 0, 'unsupported_scopes' => 0, 'scope_count' => count($world['rows']), 'purchase_requirement_by_currency' => [], 'inventory_value_by_currency' => [], 'required_inventory_capital_by_currency' => [], 'quantities_by_unit' => []];
        $summary['unknown_price_scopes'] = ['purchase_requirement_by_currency' => 0, 'inventory_value_by_currency' => 0, 'required_inventory_capital_by_currency' => 0];
        $rows = [];
        foreach ($world['rows'] as $row) {
            $i = $row['input'];
            $line = collect($lines)->firstWhere('group', $row['key']);
            $projected = $i;
            foreach ($lines as $outgoing) {
                foreach ($outgoing['transfers'] ?? [] as $t) {
                    if ($t['resource'] === $row['key']) {
                        $projected['stock']['available'] -= $t['quantity'];
                    }
                }
            }foreach ($line['purchases'] ?? [] as $p) {
                $projected['firm_incoming'][] = ['expected_at' => $p['expected_at'], 'quantity' => $p['base_quantity'], 'source' => 'hypothetical_response'];
            }foreach ($line['transfers'] ?? [] as $t) {
                $projected['firm_incoming'][] = ['expected_at' => $t['expected_at'], 'quantity' => $t['quantity'], 'source' => 'hypothetical_response'];
            }
            $math = app(InventoryPlanningMath::class)->calculate($projected, ['base_quantity' => 0]);
            $h = $world['strategic_horizon'];
            $path = [];
            foreach (array_slice($math['timeline'], 0, $h) as $r) {
                $path[] = ['date' => $r['date'], 'demand' => $r['demand'], 'incoming' => array_sum(array_column(array_filter($projected['firm_incoming'], fn ($f) => $f['expected_at'] === $r['date']), 'quantity')), 'balance' => $r['baseline'], 'safety_stock' => $math['safety_stock'], 'stockout' => $r['baseline'] < -.0005, 'below_target' => $r['baseline'] < $math['safety_stock']];
            }
            $required = $path ? max(0, $math['safety_stock'] - min(array_column($path, 'balance'))) : null;
            $estimate = null;
            if ($required !== null && $i['suppliers']) {
                $purchase = app(InventoryPlanningMath::class)->calculate($i, ['base_quantity' => ceil(max($required, $math['moq']) / max(.001, $math['step'])) * max(.001, $math['step'])]);
                $estimate = $required > 0 ? $purchase['base_cost'] : '0.00';
            }
            $unit = $row['product']['unit'];
            $c = $world['currency'];
            $cost = $i['valuation_unit_cost'] ?? null;
            $value = $cost === null ? null : Money::multiply(max(0, $i['stock']['available']), $cost);
            $capital = $cost === null || $required === null ? null : Money::multiply($required, $cost);
            if ($required === 0.0 || $required === 0) {
                $estimate = '0.00';
            }
            foreach (['purchase_requirement_by_currency' => $estimate, 'inventory_value_by_currency' => $value, 'required_inventory_capital_by_currency' => $capital] as $metric => $amount) {
                if ($amount === null) {
                    $summary['unknown_price_scopes'][$metric]++;
                }
            }
            if ($estimate !== null) {
                $summary['purchase_requirement_by_currency'][$c] = Money::add($summary['purchase_requirement_by_currency'][$c] ?? 0, $estimate);
            }if ($value !== null) {
                $summary['inventory_value_by_currency'][$c] = Money::add($summary['inventory_value_by_currency'][$c] ?? 0, $value);
            }if ($capital !== null) {
                $summary['required_inventory_capital_by_currency'][$c] = Money::add($summary['required_inventory_capital_by_currency'][$c] ?? 0, $capital);
            }
            $summary['quantities_by_unit'][$unit] = round(($summary['quantities_by_unit'][$unit] ?? 0) + ($required ?? 0), 3);
            $summary['stockout_exposures'] += (int) ($path && min(array_column($path, 'balance')) < -.0005);
            $summary['below_target_scopes'] += (int) ($required > 0);
            $summary['unsupported_scopes'] += (int) (count($path) < $h);
            $rows[] = ['key' => $row['key'], 'product' => $row['product'], 'warehouse' => $row['warehouse'], 'unit' => $unit, 'safety_stock' => $math['safety_stock'], 'required_base_quantity' => $required, 'purchase_estimate' => $estimate, 'valuation' => $value, 'capital_requirement' => $capital, 'currency' => $c, 'timeline' => $path, 'coverage_days' => count($path), 'confidence' => $row['confidence'], 'suppliers' => $row['suppliers'], 'qualified_service_target' => $math['quality']['service_target_validated'], 'assumptions' => $math['assumptions']];
        }$summary['money_qualification'] = 'Known-price scoped estimates only; unknown costs excluded, not assumed zero. Available stock valuation excludes holds and is not the company balance sheet.';

        return ['summary' => $summary, 'rows' => $rows, 'finance' => $this->finance($world, $lines)];
    }

    private function finance(array $world, array $lines = []): array
    {
        $e = $world['v7']['evidence'] ?? null;
        if (! $e) {
            return ['state' => 'unavailable', 'reason' => 'Frozen financial evidence unavailable.'];
        }
        foreach ($e['commitments'] as &$commitment) {
            if (($commitment['payment_basis'] ?? null) === 'arrival' && isset($world['arrival_shifts'][$commitment['purchase_order_id'] ?? 0]) && ! empty($commitment['expected_date'])) {
                $commitment['expected_date'] = max($e['as_of'], Date::parse($commitment['expected_date'])->addDays($world['arrival_shifts'][$commitment['purchase_order_id']])->toDateString());
            }
        }unset($commitment);
        foreach ($world['financial_assumptions'] ?? [] as $a) {
            foreach ($e['receivables'] as &$r) {
                if (! empty($r['expected_date']) && ($a['type'] === 'financial' && $a['action'] === 'collections_delay' || $a['type'] === 'customer' && $a['action'] === 'payment_delay' && ($r['customer_id'] ?? null) === $a['customer_id'])) {
                    $r['expected_date'] = Date::parse($r['expected_date'])->addDays($a['days'])->toDateString();
                }
            }unset($r);
            foreach ($e['commitments'] as &$r) {
                if ($a['type'] === 'financial' && $a['action'] === 'payments_advance' && ! empty($r['expected_date'])) {
                    $r['expected_date'] = max($e['as_of'], Date::parse($r['expected_date'])->subDays($a['days'])->toDateString());
                }if ($a['type'] === 'financial' && $a['action'] === 'expense_percent' && ($r['source_type'] ?? null) === 'supplier_payable') {
                    $r['amount'] = Money::multiply($r['amount'], 1 + $a['value'] / 100);
                }
            }unset($r);
        }
        foreach ($lines as $line) {
            foreach ($line['purchases'] as $p) {
                $e['commitments'][] = ['key' => 'simulation-response:'.$line['group'].':'.$p['supplier_id'], 'reference' => 'Hypothetical response purchase', 'amount' => $p['base_cost'], 'currency' => $world['currency'], 'expected_date' => $p['order_date'], 'evidence_type' => 'scenario', 'payment_basis' => 'hypothetical_full_payment_on_order'];
            }
        }
        $out = app(FinancialForecastMath::class)->calculate($e, $world['strategic_horizon']);
        $out['qualification'] = 'V7 recorded obligations only. Response purchases assume full payment on order date in company currency at frozen recorded FX for comparison; no real payable/payment is created. No future revenue or transfer costs are invented.';

        return $out;
    }

    private function responses(array $world, array &$warnings): array
    {
        $short = $world;
        foreach ($short['rows'] as &$r) {
            $r['input']['daily'] = array_slice($r['input']['daily'], 0, $short['scope']['horizon']);
        }unset($r);
        $payload = app(SupplyOptimizationCandidates::class)->build($short);
        // A closed destination cannot make new response supply usable early. Re-score
        // the same V11 bundles after delaying availability, rather than letting the
        // optimizer choose against an optimistic timeline that the result later hides.
        foreach ($payload['groups'] as &$group) {
            $row = collect($short['rows'])->firstWhere('key', $group['key']);
            $until = $row['input']['simulation_unavailable_until'] ?? null;
            if (! $until) {
                continue;
            }
            $row['baseline'] = app(InventoryPlanningMath::class)->calculate($row['input'], ['base_quantity' => 0]);
            $row['need'] = $group['need'];
            foreach ($group['candidates'] as &$candidate) {
                $purchases = $candidate['purchases'];
                $transfers = $candidate['transfers'];
                foreach ($purchases as &$purchase) {
                    $purchase['expected_at'] = max($purchase['expected_at'], $until);
                }
                unset($purchase);
                foreach ($transfers as &$transfer) {
                    $transfer['expected_at'] = max($transfer['expected_at'], $until);
                }
                unset($transfer);
                $order = $purchases[0]['order_date'] ?? $short['as_of'];
                $delay = (int) Date::parse($short['as_of'])->diffInDays(Date::parse($order), false);
                $candidate = app(SupplyOptimizationCandidates::class)->candidate($row, $purchases, $transfers, $delay, $candidate['action'], $short);
            }
            unset($candidate);
            $group['candidates'] = array_values(array_filter($group['candidates']));
        }
        unset($group);
        if (isset($world['allow_split']) && ! $world['allow_split']) {
            foreach ($payload['groups'] as &$g) {
                $g['candidates'] = array_values(array_filter($g['candidates'], fn ($c) => count($c['purchases']) <= 1));
            }
        }unset($g);
        if (collect($payload['groups'])->contains(fn ($g) => ! $g['candidates'])) {
            return [['key' => 'balanced', 'status' => 'INFEASIBLE', 'lines' => [], 'summary' => [], 'message' => 'No valid candidate meets declared constraints.']];
        }
        $solved = app(LocalSupplyOptimizer::class)->solve($payload);
        $candidates = collect($payload['groups'])->flatMap(fn ($g) => $g['candidates'])->keyBy('id');
        $out = [];
        foreach ($solved['plans'] as $a) {
            $lines = array_map(fn ($id) => $candidates[$id], $a['candidate_ids'] ?? []);
            $view = $this->project($world, $lines);
            $a['lines'] = $lines;
            $a['summary'] = $view['summary'] + ['commitment' => Money::decimal(array_sum(array_column($lines, 'cost_minor'))), 'currency' => $world['currency'], 'transfer_lines' => array_sum(array_map(fn ($c) => count($c['transfers']), $lines)), 'unresolved_scopes' => $view['summary']['below_target_scopes']];
            $a['rows'] = $view['rows'];
            $a['finance'] = $view['finance'];
            $a['recommended'] = $a['key'] === 'balanced';
            $out[] = $a;
        }

        return $out;
    }

    private function bottlenecks(array $world, array $scenario, array $alternatives): array
    {
        $rows = [];
        foreach ($scenario['rows'] as $r) {
            if ($r['required_base_quantity'] > 0 || ! $r['timeline']) {
                $rows[] = ['product' => $r['product'], 'warehouse' => $r['warehouse'], 'reason' => ! $r['timeline'] ? 'insufficient_demand_evidence' : (! $r['suppliers'] ? 'no_eligible_supplier' : 'dated_supply_demand_gap'), 'stockout_days' => count(array_filter($r['timeline'], fn ($d) => $d['stockout'])), 'first_stockout' => collect($r['timeline'])->firstWhere('stockout', true)['date'] ?? null, 'required_quantity' => $r['required_base_quantity'], 'unit' => $r['unit']];
            }
        }usort($rows, fn ($a, $b) => $b['stockout_days'] <=> $a['stockout_days']);

        return ['rows' => $rows, 'qualification' => 'Observed consequence ranking, not a causal attribution score. Inspect sensitivity to determine which assumption dominates.', 'commitment_limit' => $world['scope']['commitment_limit'] ?? null];
    }

    public function sensitivity(array $baseline, array $assumptions, array $request): array
    {
        $index = $request['assumption_index'];
        $values = $request['values'];
        $points = [];
        foreach ($values as $value) {
            $changed = $assumptions;
            $field = isset($changed[$index]['days']) ? 'days' : 'value';
            $changed[$index][$field] = $value;
            $r = $this->calculate($baseline, $changed, false);
            $points[] = ['value' => $value, 'summary' => $r['scenario']['summary'], 'impact' => $r['impact']];
        }

        return ['assumption_index' => $index, 'points' => $points, 'breakpoints' => array_values(array_filter($points, fn ($p) => $p['impact']['stockout_exposures'] > 0)), 'qualification' => 'Tested discrete values only. The first adverse tested point brackets a vulnerability threshold; it is not an exact continuous breakpoint.', 'read_only' => true];
    }
}
