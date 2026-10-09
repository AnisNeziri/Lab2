<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerSalesSnapshot;
use App\Models\Shipment;
use App\Models\Supplier;
use Illuminate\Support\Str;

/** Deterministic language-to-typed-assumptions adapter; no language-model math. */
final class StrategicSimulationAssistant
{
    public function supports(string $question, array $context): bool
    {
        $q = Str::lower(Str::ascii($question));
        if (preg_match('/(?:supply |optimizer |optimization )?plan\\s*#\\d+/', $q) || isset($context['entity']) && ! isset($context['strategic_simulation_id'])) {
            return false;
        }

        return (! isset($context['optimization_plan_id']) && preg_match('/\b(what if|what happens if|simulate|simulo|po nese|cfare ndodh nese)\b/', $q) && preg_match('/demand|supplier|shipping|shipment|sh-[a-z0-9]|customer|payments|collections|inventory|stock|service|purchas|warehouse|kerkesa|furnitor|derges|klient|pages|stok|depo/', $q)) || isset($context['strategic_simulation_id']) && preg_match('/^(and |also |keep |what can|why |explain |show |compare |dhe |mbaj |pse |shpjego |trego |krahaso )/', $q);
    }

    public function reply(string $question, array &$context, bool $sq): array
    {
        $s = app(StrategicSimulationService::class);
        $s->authorize();
        $q = Str::lower(Str::ascii($question));
        $pid = $context['strategic_simulation_id'] ?? null;
        $tool = 'run_strategic_simulation';
        $args = [];
        if ($pid && preg_match('/^(why|explain|pse|shpjego)/', $q)) {
            $tool = 'explain_simulation_result';
            $data = $s->explain($pid);
        } elseif ($pid && preg_match('/what can.*do|optimize.*response|cfare.*bej|optimizo.*pergjigj/', $q)) {
            $tool = 'optimize_simulation_response';
            $data = $s->optimize($pid);
        } elseif ($pid && preg_match('/^(show|trego)/', $q)) {
            $tool = 'get_simulation_result';
            $data = $s->get($pid);
        } elseif ($pid && preg_match('/^(compare|krahaso)/', $q)) {
            $old = $s->get($pid);
            $other = $old['parent_id'] ?? null;
            if (preg_match('/#(\d+)/', $q, $m)) {
                $other = (int) $m[1];
            }if (! $other) {
                throw new \InvalidArgumentException('Select two saved scenarios or specify the other run number (#123).');
            }$tool = 'compare_simulations';
            $data = app(AssistantToolRunner::class)->execute($tool, ['ids' => [$pid, $other]]);
        } else {
            $assumptions = [];
            $percent = null;
            if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*%/', $q, $m)) {
                $percent = (float) $m[1];
            }$days = 0;
            if (preg_match('/(\d+)\s*(?:days|day|dite)/', $q, $m)) {
                $days = (int) $m[1];
            } elseif (preg_match('/(one|two|three|four|nje|dy|tri|tre|kater)\s*(?:weeks|week|jave)/', $q, $m)) {
                $days = (['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'nje' => 1, 'dy' => 2, 'tri' => 3, 'tre' => 3, 'kater' => 4][$m[1]]) * 7;
            }
            if (preg_match('/demand|kerkesa/', $q) && ! preg_match('/customer|klient/', $q) && $percent !== null) {
                $assumptions[] = ['type' => 'demand', 'action' => 'percent', 'value' => preg_match('/decrease|reduce|down|ulet|ulje/', $q) ? -$percent : $percent];
            }
            if (preg_match('/supplier|furnitor/', $q) || ($pid && $days && preg_match('/late|vone/', $q) && ! preg_match('/shipment|customer|derges|klient/', $q))) {
                $id = $this->resolve(Supplier::class, $q);
                $action = preg_match('/unavailable|unavailable|cannot|unavailable|padispon|mungon/', $q) ? 'unavailable' : (preg_match('/price|cmim/', $q) ? 'price_percent' : 'lead_days');
                $assumptions[] = ['type' => 'supplier', 'action' => $action, 'supplier_id' => $id] + ($action === 'price_percent' ? ['value' => $percent ?? throw new \InvalidArgumentException('Specify the supplier price percentage.')] : ['days' => $days ?: throw new \InvalidArgumentException('Specify the supplier delay/unavailability days.')]);
            }
            if (preg_match('/shipment|shipping|transit|derges|transport|route|sh-[a-z0-9]/', $q)) {
                $a = ['type' => 'logistics', 'action' => 'delay', 'days' => $days ?: throw new \InvalidArgumentException('Specify the delay in days or weeks.')];
                if (preg_match('/\b(sh-[a-z0-9_-]+)\b/', $q, $m)) {
                    $a['shipment_id'] = $this->resolve(Shipment::class, $m[1], 'tracking_number');
                } elseif (preg_match('/shipment\s+["“]?(.+?)["”]?\s+(?:is|arrives|late|delayed)/', $q, $m)) {
                    $a['shipment_id'] = $this->resolve(Shipment::class, $m[1], 'tracking_number');
                }$assumptions[] = $a;
            }
            if (preg_match('/collections|payments|arketime|pages/', $q) && ! preg_match('/supplier|furnitor/', $q)) {
                $assumptions[] = ['type' => 'financial', 'action' => 'collections_delay', 'days' => $days ?: throw new \InvalidArgumentException('Specify payment delay days.')];
            }
            if (preg_match('/customer|klient/', $q) && preg_match('/demand|orders|kerkesa|porosi/', $q)) {
                $id = preg_match('/top customer|major customer|klienti kryesor/', $q) ? $this->topCustomer() : $this->resolve(Customer::class, $q);
                $change = preg_match('/double|doubles|dyfish/', $q) ? 100 : ($percent ?? throw new \InvalidArgumentException('Specify the customer demand percentage.'));
                $assumptions[] = ['type' => 'customer', 'action' => 'percent', 'customer_id' => $id, 'value' => $change];
            }
            if (preg_match('/reduce.*(?:inventory|stock)|ul.*stok/', $q) && $percent !== null) {
                $assumptions[] = ['type' => 'inventory', 'action' => 'reduction_percent', 'value' => $percent];
            }
            if (preg_match('/service|sherbim/', $q)) {
                $target = $percent ?? throw new \InvalidArgumentException('Specify an existing 90%, 95%, 98% or 99% service target.');
                $assumptions[] = ['type' => 'inventory', 'action' => 'service_level', 'value' => $target / 100];
            }
            if (preg_match('/purchas|commitment|limit|ble|kufi/', $q) && preg_match('/(?:€|eur\s*|under\s*|below\s*)([0-9][0-9,]*(?:\.[0-9]{1,2})?)/', $q, $m)) {
                $assumptions[] = ['type' => 'procurement', 'action' => 'commitment_limit', 'value' => (float) str_replace(',', '', $m[1])];
            }
            if (! $assumptions) {
                throw new \InvalidArgumentException('Use the scenario builder to review this assumption; no unrecognized scenario is guessed.');
            }
            if ($pid) {
                $old = $s->get($pid);
                $merged = $old['definition']['assumptions'];
                foreach ($assumptions as $a) {
                    $sig = fn ($r) => [$r['type'], $r['action'], $r['product_id'] ?? null, $r['supplier_id'] ?? null, $r['customer_id'] ?? null, $r['shipment_id'] ?? null];
                    $merged = array_values(array_filter($merged, fn ($r) => $sig($r) !== $sig($a)));
                    $merged[] = $a;
                }$args = array_filter($old['definition'], fn ($value) => $value !== null);
                $args['assumptions'] = $merged;
                $args['parent_id'] = $pid;
                $data = app(AssistantToolRunner::class)->execute('run_strategic_simulation', $args);
                $tool = 'run_strategic_simulation';
            } else {
                $h = 90;
                if (preg_match('/next\s+(30|60|90|180|365)\s+days/', $q, $m)) {
                    $h = (int) $m[1];
                }$args = ['name' => mb_substr($question, 0, 150), 'horizon' => $h, 'scope' => [], 'assumptions' => $assumptions, 'optimize' => true];
                $data = app(AssistantToolRunner::class)->execute('run_strategic_simulation', $args);
            }
        }
        if (isset($data['id'])) {
            $context['strategic_simulation_id'] = $data['id'];
        }$id = $data['id'] ?? $pid;
        $summary = $data['result']['scenario']['summary'] ?? $data['summary'] ?? null;
        $metrics = [];
        if ($summary) {
            foreach (['stockout_exposures', 'below_target_scopes', 'unsupported_scopes'] as $key) {
                $metrics[] = ['label' => $sq ? (['stockout_exposures' => 'Shtrirje me mungesë stoku', 'below_target_scopes' => 'Nën objektiv', 'unsupported_scopes' => 'Dëshmi të pamjaftueshme'][$key]) : str_replace('_', ' ', $key), 'value' => $summary[$key] ?? null, 'unit' => ''];
            }
        }

        return ['intent' => 'strategic_simulation', 'text' => $sq ? 'Skenar i izoluar, jo parashikim i garantuar. Hap simulimin për statusin, pasojat dhe strategjitë. Nuk u krijua asnjë porosi, pagesë ose lëvizje stoku.' : 'Isolated scenario, not a guaranteed future. Open the simulation for status, consequences and response strategies. No order, payment or stock movement was created.', 'cards' => [['tool' => $tool, 'title' => $data['name'] ?? ($sq ? 'Simulim strategjik' : 'Strategic simulation'), 'status' => $data['status'] ?? null, 'url' => '/strategic-simulation?run='.$id, 'metrics' => $metrics, 'evidence' => ['baseline_at' => $data['baseline_at'] ?? null, 'definition' => $data['definition'] ?? null, 'impact' => $data['result']['impact'] ?? $data['impact'] ?? null, 'bottlenecks' => $data['result']['bottlenecks'] ?? $data['bottlenecks'] ?? null, 'alternatives' => $data['result']['alternatives'] ?? $data['alternatives'] ?? []]]], 'sources' => [['tool' => $tool, 'url' => '/strategic-simulation?run='.$id, 'as_of' => $data['baseline_at'] ?? null, 'read_at' => now()->toIso8601String()]], 'limitations' => $data['result']['limitations'] ?? $data['limitations'] ?? [], 'choices' => [], 'provider' => 'deterministic', 'read_only' => true, 'scenario' => true, 'context' => null, 'state' => 'success'];
    }

    private function resolve(string $class, string $text, string $field = 'name'): int
    {
        $needle = Str::lower(Str::ascii($text));
        $matches = $class::orderBy('id')->limit(500)->get(['id', $field])->filter(fn ($r) => str_contains($needle, Str::lower(Str::ascii($r->$field))))->sortByDesc(fn ($r) => strlen($r->$field));
        if (! $matches->count()) {
            throw new \InvalidArgumentException('Select an exact company record in the simulation builder; the named source was not found.');
        }$max = strlen($matches->first()->$field);
        abort_unless($matches->filter(fn ($r) => strlen($r->$field) === $max)->count() === 1, 422, 'The source name is ambiguous. Select it in the scenario builder.');

        return (int) $matches->first()->id;
    }

    private function topCustomer(): int
    {
        $latest = CustomerSalesSnapshot::whereNull('evidence->archived')->latest('id')->first();
        $c = $latest?->evidence['concentration']['groups']['customers'][0]['id'] ?? null;
        if (! $c || ! Customer::whereKey($c)->exists()) {
            throw new \InvalidArgumentException('Top-customer evidence is unavailable; specify the customer name.');
        }

        return (int) $c;
    }
}
