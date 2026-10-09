<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/** One public, bounded assumption schema used by HTTP and assistant tools. */
final class StrategicSimulationDefinition
{
    public const ACTIONS = ['demand' => ['percent'], 'supplier' => ['unavailable', 'lead_days', 'price_percent', 'moq', 'risk_percent', 'remove'], 'logistics' => ['delay'], 'inventory' => ['safety_percent', 'service_level', 'reduction_percent', 'extra_cover_days'], 'warehouse' => ['unavailable', 'rebalance'], 'procurement' => ['commitment_limit', 'purchase_delay', 'replenishment_multiplier', 'restrict_supplier', 'allow_split'], 'financial' => ['collections_delay', 'payments_advance', 'expense_percent'], 'customer' => ['percent', 'inactive', 'additional_quantity', 'payment_delay']];

    public function validate(array $input): array
    {
        $v = validator($input, ['name' => 'required|string|max:160', 'description' => 'nullable|string|max:3000', 'horizon' => 'required|integer|in:30,60,90,180,365', 'optimize' => 'sometimes|boolean', 'scope' => 'sometimes|array:product_ids,warehouse_ids,supplier_ids,category_ids,allow_transfers,transfer_lead_days,long_horizon_mode', 'scope.product_ids' => 'sometimes|array|max:40', 'scope.product_ids.*' => 'integer|distinct|min:1', 'scope.warehouse_ids' => 'sometimes|array|max:3', 'scope.warehouse_ids.*' => 'integer|distinct|min:1', 'scope.supplier_ids' => 'sometimes|array|max:6', 'scope.supplier_ids.*' => 'integer|distinct|min:1', 'scope.category_ids' => 'sometimes|array|max:20', 'scope.category_ids.*' => 'integer|distinct|min:1', 'scope.allow_transfers' => 'sometimes|boolean', 'scope.transfer_lead_days' => 'nullable|integer|min:0|max:30', 'scope.long_horizon_mode' => 'sometimes|in:no_extension,repeat_pattern', 'assumptions' => 'present|array|max:24', 'assumptions.*' => 'array:type,action,value,days,product_id,category_id,supplier_id,customer_id,shipment_id,warehouse_id,source_warehouse_id,start_day,end_day,route_from,route_to', 'assumptions.*.type' => 'required|string', 'assumptions.*.action' => 'required|string', 'assumptions.*.value' => 'sometimes|numeric|min:-100|max:100000000', 'assumptions.*.days' => 'sometimes|integer|min:-365|max:365', 'assumptions.*.start_day' => 'sometimes|integer|min:0|max:364', 'assumptions.*.end_day' => 'sometimes|integer|min:0|max:364', 'assumptions.*.route_from' => 'sometimes|string|min:2|max:120', 'assumptions.*.route_to' => 'sometimes|string|min:2|max:120'])->validate();
        $classes = ['product_id' => Product::class, 'category_id' => Category::class, 'supplier_id' => Supplier::class, 'customer_id' => Customer::class, 'shipment_id' => Shipment::class, 'warehouse_id' => Warehouse::class, 'source_warehouse_id' => Warehouse::class];
        $scope = $v['scope'] ?? [];
        foreach (['product_ids' => Product::class, 'warehouse_ids' => Warehouse::class, 'supplier_ids' => Supplier::class, 'category_ids' => Category::class] as $key => $class) {
            $scope[$key] = array_values(array_unique(array_map('intval', $scope[$key] ?? [])));
            sort($scope[$key]);
            if ($scope[$key] && $class::whereIn('id', $scope[$key])->count() !== count($scope[$key])) {
                $this->fail('scope', 'Select available records from this company.');
            }
        }
        foreach ($v['assumptions'] as $n => &$a) {
            $key = 'assumptions.'.$n;
            if (! in_array($a['action'], self::ACTIONS[$a['type']] ?? [], true)) {
                $this->fail($key, 'Select a supported assumption.');
            }
            foreach ($classes as $field => $class) {
                if (isset($a[$field])) {
                    $id = filter_var($a[$field], FILTER_VALIDATE_INT);
                    if (! $id || ! $class::whereKey($id)->exists()) {
                        $this->fail($key.'.'.$field, 'Select a record in this company.');
                    }$a[$field] = $id;
                }
            }
            $required = match ($a['type']) {
                'supplier' => ['supplier_id'],'customer' => ['customer_id'],'warehouse' => $a['action'] === 'rebalance' ? ['source_warehouse_id', 'warehouse_id', 'product_id'] : ['warehouse_id'],default => []
            };
            if ($a['action'] === 'restrict_supplier') {
                $required[] = 'supplier_id';
            }if ($a['action'] === 'additional_quantity') {
                $required[] = 'product_id';
            }foreach ($required as $f) {
                if (! isset($a[$f])) {
                    $this->fail($key.'.'.$f, 'This assumption requires a selection.');
                }
            }
            $none = in_array($a['action'], ['remove', 'restrict_supplier'], true);
            $isDays = in_array($a['action'], ['unavailable', 'lead_days', 'delay', 'extra_cover_days', 'purchase_delay', 'collections_delay', 'payments_advance', 'inactive', 'payment_delay'], true);
            $f = $isDays ? 'days' : 'value';
            if (! $none && ! isset($a[$f])) {
                $this->fail($key.'.'.$f, 'Enter a numeric assumption value.');
            }
            if (isset($a['value'])) {
                $a['value'] = (float) $a['value'];
                $max = match ($a['action']) {
                    'percent','price_percent','safety_percent','expense_percent' => 500,'risk_percent','reduction_percent' => 100,'replenishment_multiplier' => 3,'allow_split' => 1,'service_level' => .99,default => 100000000
                };
                $min = in_array($a['action'], ['percent', 'price_percent', 'safety_percent', 'risk_percent', 'expense_percent'], true) ? -100 : 0;
                if ($a['value'] < $min || $a['value'] > $max) {
                    $this->fail($key.'.value', 'The assumption value is outside supported bounds.');
                }if ($a['action'] === 'service_level' && ! in_array($a['value'], [.9, .95, .98, .99], true)) {
                    $this->fail($key.'.value', 'Use an existing planning target: 90%, 95%, 98% or 99%.');
                }if ($a['action'] === 'allow_split' && ! in_array($a['value'], [0.0, 1.0], true)) {
                    $this->fail($key.'.value', 'Choose allowed or not allowed.');
                }
            }
            if (isset($a['days']) && $a['days'] < 0 && $a['action'] !== 'lead_days') {
                $this->fail($key.'.days', 'Days must not be negative for this assumption.');
            }
            if (($a['end_day'] ?? 364) < ($a['start_day'] ?? 0)) {
                $this->fail($key, 'End day must not precede start day.');
            }
            if (($a['start_day'] ?? 0) > 0 && ! in_array($a['type'], ['demand', 'customer'], true)) {
                $this->fail($key, 'Period-specific assumptions are supported for demand and customer changes. Other changes start at the baseline.');
            }
            if (isset($a['route_from']) xor isset($a['route_to'])) {
                $this->fail($key, 'Specify both route origin and destination.');
            }
            if (isset($a['shipment_id']) && isset($a['route_from'])) {
                $this->fail($key, 'Select a shipment or a recorded route, not both.');
            }
            if (isset($a['product_id']) && $scope['product_ids'] && ! in_array($a['product_id'], $scope['product_ids'])) {
                $this->fail($key, 'The selected product must be in the simulation scope.');
            }
            if ($a['type'] === 'customer' && $a['action'] === 'additional_quantity' && count($scope['warehouse_ids']) > 1 && ! isset($a['warehouse_id'])) {
                $this->fail($key, 'Select the warehouse for an additional customer quantity; it must not be repeated in every warehouse.');
            }
            if (isset($a['warehouse_id']) && ! in_array($a['warehouse_id'], $scope['warehouse_ids'])) {
                $this->fail($key, 'Include the selected warehouse in the simulation scope.');
            }
            if ($a['type'] === 'warehouse') {
                foreach (array_filter([$a['warehouse_id'] ?? null, $a['source_warehouse_id'] ?? null]) as $w) {
                    if (! in_array($w, $scope['warehouse_ids'])) {
                        $this->fail($key, 'Include both affected warehouses in the simulation scope.');
                    }
                }if (($a['warehouse_id'] ?? null) === ($a['source_warehouse_id'] ?? 0)) {
                    $this->fail($key, 'Source and destination must differ.');
                }
            }
        }unset($a);
        $scope['long_horizon_mode'] ??= 'no_extension';
        $scope['allow_transfers'] ??= true;
        $v['scope'] = $scope;
        $v['optimize'] ??= true;

        return $v;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
