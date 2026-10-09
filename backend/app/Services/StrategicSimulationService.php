<?php

namespace App\Services;

use App\Jobs\RunStrategicSimulation;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Shipment;
use App\Models\StrategicSimulation as Run;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Isolated run persistence/orchestration. Operational conversion is a separate explicit boundary. */
class StrategicSimulationService
{
    public const PERMISSIONS = ['analytics.view', 'analytics.finance', 'inventory.view', 'procurement.view', 'finance.view', 'financial_accounts.view', 'customers.manage', 'daily_sales.manage', 'shipments.view'];

    public function authorize(): void
    {
        abort_unless(Auth::user()?->company_id, 403);
        foreach (self::PERMISSIONS as $p) {
            abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, $p), 403);
        }
    }

    public function options(): array
    {
        $this->authorize();
        $o = app(SupplyOptimizerService::class)->options();
        $o['customers'] = Customer::orderBy('name')->get(['id', 'name']);
        $o['shipments'] = Shipment::whereNull('archived_at')->orderByDesc('id')->limit(200)->get(['id', 'tracking_number', 'origin_port', 'destination_port'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->tracking_number, 'origin' => $s->origin_port, 'destination' => $s->destination_port]);
        $o['templates'] = $this->templates();
        $o['baseline_hint'] = ['captured_at' => null, 'qualification' => 'Current facts freeze in the background after confirmation; no preview numbers are fabricated.'];

        return $o;
    }

    public function summary(): array
    {
        $this->authorize();

        return ['rows' => Run::latest('id')->limit(40)->get(['id', 'version', 'name', 'description', 'status', 'stage', 'definition', 'baseline_at', 'parent_id', 'created_at', 'error']), 'read_only' => true];
    }

    public function get(int $id): array
    {
        $this->authorize();
        $r = Run::findOrFail($id);
        $data = $r->toArray();
        unset($data['baseline']);
        $data['permissions'] = ['can_prepare_response' => app(PermissionService::class)->roleHasPermission(Auth::user()->role, 'procurement.manage')];
        $data['baseline_summary'] = $r->baseline ? ['at' => $r->baseline_at?->toIso8601String(), 'versions' => $r->baseline['versions'], 'source_refs' => $r->baseline['source_refs'], 'currency' => $r->baseline['currency'], 'known_predicted' => $r->baseline['known_predicted'], 'scopes' => count($r->baseline['rows'])] : null;

        return $data;
    }

    public function state(int $id): array
    {
        $this->authorize();

        return Run::findOrFail($id, ['id', 'status', 'stage', 'updated_at', 'error'])->toArray();
    }

    public function submit(array $input): array
    {
        $this->authorize();

        return $this->enqueue(app(StrategicSimulationDefinition::class)->validate($input));
    }

    private function enqueue(array $definition, ?Run $parent = null, bool $frozen = false): array
    {
        return DB::transaction(function () use ($definition, $parent, $frozen) {
            Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();
            $key = hash('sha256', json_encode([$definition, $parent?->id, $frozen ? $parent?->version : 'current']));
            $old = Run::where('request_key', $key)->whereIn('status', ['QUEUED', 'RUNNING'])->first();
            if ($old) {
                return $this->get($old->id);
            }
            $r = Run::create(['version' => (string) Str::uuid(), 'engine_version' => StrategicSimulationEngine::VERSION, 'name' => $definition['name'], 'description' => $definition['description'] ?? null, 'definition' => $definition, 'status' => 'QUEUED', 'stage' => 'preparing_baseline', 'created_by' => Auth::id(), 'parent_id' => $parent?->id, 'request_key' => $key, 'baseline' => $frozen ? $parent->baseline : null, 'baseline_at' => $frozen ? $parent->baseline_at : null, 'audit' => [['action' => $frozen ? 'derived_frozen_run' : 'requested_current_run', 'actor_id' => Auth::id(), 'at' => now()->toIso8601String(), 'parent_id' => $parent?->id]]]);
            RunStrategicSimulation::dispatch($r->id)->onConnection('database')->onQueue('strategic-simulation')->afterCommit();

            return $this->get($r->id);
        });
    }

    public function run(int $id): void
    {
        $r = Run::withoutGlobalScopes()->findOrFail($id);
        $actor = User::withoutGlobalScopes()->find($r->created_by);
        $prior = Auth::user();
        try {
            abort_unless($actor && $actor->is_active && $actor->company_id === $r->company_id, 403, 'Requester is no longer available.');
            Auth::setUser($actor);
            $this->authorize();
            if (! Run::whereKey($id)->where('status', 'QUEUED')->update(['status' => 'RUNNING', 'updated_at' => now()])) {
                return;
            }$r->refresh();
            $this->stage($r, 'preparing_baseline');
            if (! $r->baseline) {
                $baseline = app(StrategicSimulationBaseline::class)->freeze($r->definition);
                $this->active($r);
                $r->update(['baseline' => $baseline, 'baseline_at' => $baseline['cutoff']]);
            }
            $this->stage($r, 'applying_assumptions');
            $this->stage($r, 'projecting_inventory');
            if ($r->definition['optimize']) {
                $this->stage($r, 'optimizing_response');
            }
            $result = app(StrategicSimulationEngine::class)->calculate($r->baseline, $r->definition['assumptions'], $r->definition['optimize']);
            $this->stage($r, 'evaluating_finance');
            $this->active($r);
            $audit = $r->audit;
            $audit[] = ['action' => 'simulation.completed', 'actor_id' => $r->created_by, 'at' => now()->toIso8601String(), 'input_hash' => hash('sha256', json_encode([$r->baseline, $r->definition])), 'engine_version' => $r->engine_version];
            DB::transaction(function () use ($r, $result, $audit) {
                $locked = Run::whereKey($r->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'RUNNING') {
                    $locked->update(['status' => 'COMPLETED', 'stage' => 'completed', 'result' => $result, 'audit' => $audit]);
                }
            });
        } catch (\Throwable $e) {
            $r->refresh();
            if ($r->status !== 'CANCELLED') {
                $r->update(['status' => 'FAILED', 'stage' => 'failed', 'error' => Str::limit($e->getMessage(), 1800)]);
            }
        } finally {
            if ($prior) {
                Auth::setUser($prior);
            } else {
                Auth::forgetUser();
            }
        }
    }

    private function active(Run $r): void
    {
        $r->refresh();
        if ($r->status === 'CANCELLED') {
            throw new \RuntimeException('Simulation cancelled.');
        }
    }

    private function stage(Run $r, string $stage): void
    {
        $this->active($r);
        $r->update(['stage' => $stage]);
    }

    public function cancel(int $id): array
    {
        $this->authorize();
        DB::transaction(function () use ($id) {
            $r = Run::lockForUpdate()->findOrFail($id);
            abort_unless(in_array($r->status, ['QUEUED', 'RUNNING', 'CANCELLED']), 422, 'Only an active simulation can be cancelled.');
            if ($r->status !== 'CANCELLED') {
                $r->update(['status' => 'CANCELLED', 'stage' => 'cancelled']);
            }
        });

        return $this->get($id);
    }

    public function rerun(int $id, array $changes = []): array
    {
        $this->authorize();
        $r = Run::findOrFail($id);
        $v = app(StrategicSimulationDefinition::class)->validate(array_replace($r->definition, $changes));

        return $this->enqueue($v, $r);
    }

    public function derive(int $id, array $changes = []): array
    {
        $this->authorize();
        $r = Run::findOrFail($id);
        abort_unless($r->baseline, 409, 'Wait for the baseline to finish before deriving a scenario.');
        $definition = array_replace($r->definition, $changes);
        $v = app(StrategicSimulationDefinition::class)->validate($definition);
        abort_if($v['scope'] !== $r->definition['scope'] || $v['horizon'] !== $r->definition['horizon'], 422, 'A frozen derived run must preserve scope and horizon; use a current-data re-run to change them.');

        return $this->enqueue($v, $r, true);
    }

    public function optimize(int $id): array
    {
        return $this->derive($id, ['optimize' => true]);
    }

    public function compare(array $ids): array
    {
        $this->authorize();
        abort_unless(count($ids) >= 2 && count($ids) <= 4 && count(array_unique($ids)) === count($ids), 422, 'Compare two to four distinct runs.');
        $runs = array_map(fn ($id) => $this->get((int) $id), $ids);
        $hashes = array_map(fn ($id) => hash('sha256', json_encode(Run::findOrFail($id)->baseline)), $ids);

        return ['runs' => $runs, 'same_baseline' => count(array_unique($hashes)) === 1, 'qualification' => 'Different baseline dates/scope are historical comparisons, not solely assumption effects.', 'read_only' => true];
    }

    public function impact(int $id): array
    {
        $r = $this->get($id);

        return ['id' => $id, 'status' => $r['status'], 'impact' => $r['result']['impact'] ?? null, 'summary' => $r['result']['scenario']['summary'] ?? null];
    }

    public function bottlenecks(int $id): array
    {
        $r = $this->get($id);

        return ['id' => $id, 'status' => $r['status'], 'bottlenecks' => $r['result']['bottlenecks'] ?? null];
    }

    public function explain(int $id): array
    {
        $r = $this->get($id);

        return ['id' => $id, 'status' => $r['status'], 'baseline_at' => $r['baseline_at'], 'definition' => $r['definition'], 'impact' => $r['result']['impact'] ?? null, 'bottlenecks' => $r['result']['bottlenecks'] ?? null, 'dependencies' => $r['result']['dependency_graph'] ?? [], 'limitations' => $r['result']['limitations'] ?? [], 'alternatives' => $r['result']['alternatives'] ?? []];
    }

    public function sensitivity(int $id, array $input): array
    {
        $this->authorize();
        $r = Run::findOrFail($id);
        abort_unless($r->status === 'COMPLETED', 409, 'Wait for a completed simulation.');
        $v = validator($input, ['assumption_index' => 'required|integer|min:0', 'values' => 'required|array|min:2|max:15', 'values.*' => 'required|numeric|distinct|min:-100|max:100000000'])->validate();
        abort_unless(isset($r->definition['assumptions'][$v['assumption_index']]), 422, 'Select a recorded assumption.');
        $a = $r->definition['assumptions'][$v['assumption_index']];
        abort_if(in_array($a['action'], ['allow_split', 'remove', 'restrict_supplier']), 422, 'Select a numeric assumption for sensitivity.');
        foreach ($v['values'] as $value) {
            $def = $r->definition;
            $def['assumptions'][$v['assumption_index']][isset($a['days']) ? 'days' : 'value'] = $value;
            app(StrategicSimulationDefinition::class)->validate($def);
        }

        return app(StrategicSimulationEngine::class)->sensitivity($r->baseline, $r->definition['assumptions'], $v);
    }

    public function prepareResponse(int $id, array $input): array
    {
        $this->authorize();
        abort_unless(app(PermissionService::class)->roleHasPermission(Auth::user()->role, 'procurement.manage'), 403);
        $v = validator($input, ['alternative' => 'required|string', 'confirm' => 'required|accepted'])->validate();

        return DB::transaction(function () use ($id, $v) {
            $r = Run::lockForUpdate()->findOrFail($id);
            if ($r->response_plan) {
                abort_unless($r->response_plan['reviewed_alternative'] === $v['alternative'], 409, 'This run already has a current-data response review. Open that plan or derive a new scenario.');

                return $r->response_plan;
            }abort_unless($r->status === 'COMPLETED', 409, 'Complete the simulation first.');
            $alternative = collect($r->result['alternatives'])->firstWhere('key', $v['alternative']);
            abort_unless($alternative && in_array($alternative['status'], ['OPTIMAL', 'FEASIBLE']), 422, 'Select a feasible simulated strategy.');
            // Never feed hypothetical quantities/prices into a real authority. Re-optimize
            // current actual data, then use V11's independent human draft confirmation.
            $scope = $r->definition['scope'];
            unset($scope['long_horizon_mode']);
            $scope['horizon'] = min(90, $r->definition['horizon']);
            foreach ($r->definition['assumptions'] as $a) {
                if ($a['type'] === 'procurement' && $a['action'] === 'commitment_limit') {
                    $scope['commitment_limit'] = $a['value'];
                }
            }$p = app(SupplyOptimizerService::class)->submit($scope);
            $link = ['plan_id' => $p['id'], 'url' => '/supply-optimizer?plan='.$p['id'], 'simulation_id' => $r->id, 'reviewed_alternative' => $v['alternative'], 'qualification' => 'Fresh current-data V11 review plan. Hypothetical assumptions/quantities are not operational instructions. No PR, transfer, PO, stock movement or journal was created.'];
            $r->update(['response_plan' => $link]);

            return $link;
        });
    }

    public function templates(): array
    {
        return [['key' => 'demand_surge', 'assumptions' => [['type' => 'demand', 'action' => 'percent', 'value' => 20]]], ['key' => 'demand_downturn', 'assumptions' => [['type' => 'demand', 'action' => 'percent', 'value' => -30]]], ['key' => 'supplier_disruption', 'assumptions' => [['type' => 'supplier', 'action' => 'unavailable', 'days' => 60]]], ['key' => 'logistics_delay', 'assumptions' => [['type' => 'logistics', 'action' => 'delay', 'days' => 15]]], ['key' => 'customer_growth', 'assumptions' => [['type' => 'customer', 'action' => 'percent', 'value' => 50]]], ['key' => 'cash_pressure', 'assumptions' => [['type' => 'financial', 'action' => 'collections_delay', 'days' => 15]]], ['key' => 'inventory_reduction', 'assumptions' => [['type' => 'inventory', 'action' => 'reduction_percent', 'value' => 20]]], ['key' => 'combined_supply_shock', 'assumptions' => [['type' => 'demand', 'action' => 'percent', 'value' => 15], ['type' => 'logistics', 'action' => 'delay', 'days' => 10], ['type' => 'financial', 'action' => 'collections_delay', 'days' => 7]]]];
    }
}
