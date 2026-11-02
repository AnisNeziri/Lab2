<?php

namespace App\Services\Platform;

use App\Models\Company;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** W3C IDs and OTLP JSON representation, with bounded, allowlisted local storage. */
class PlatformTrace
{
    private array $spans = [];

    private array $stack = [];

    private string $trace = '';

    private ?string $externalParent = null;

    private int $company = 0;

    private bool $flushing = false;

    private string $rootSpan = '';

    private array $jobs = [];

    private int $queries = 0;

    private float $dbMs = 0;

    public function begin(?string $parent = null, int $company = 0): void
    {
        $this->spans = [];
        $this->stack = [];
        $this->company = $company;
        $this->externalParent = null;
        $this->rootSpan = '';
        $this->queries = 0;
        $this->dbMs = 0;
        $this->trace = bin2hex(random_bytes(16));
        if (preg_match('/^00-([a-f0-9]{32})-([a-f0-9]{16})-[a-f0-9]{2}$/', $parent ?? '', $m) && $m[1] !== str_repeat('0', 32) && $m[2] !== str_repeat('0', 16)) {
            $this->trace = $m[1];
            $this->externalParent = $m[2];
        }
    }

    public function context(): array
    {
        return $this->trace ? ['traceparent' => '00-'.$this->trace.'-'.(end($this->stack)['id'] ?? ($this->rootSpan ?: bin2hex(random_bytes(8)))).'-01', 'company_id' => $this->company ?: Auth::user()?->company_id] : ['company_id' => $this->company ?: Auth::user()?->company_id];
    }

    public function measure(string $name, callable $fn, array $attributes = []): mixed
    {
        if (! $this->trace) {
            return $fn();
        }$span = ['id' => bin2hex(random_bytes(8)), 'parent' => end($this->stack)['id'] ?? $this->externalParent, 'name' => $name, 'start' => microtime(true), 'attributes' => $attributes];
        $this->rootSpan = $this->rootSpan ?: $span['id'];
        $this->stack[] = $span;
        $status = 'ok';
        try {
            return $fn();
        } catch (\Throwable $e) {
            $status = 'error';
            throw $e;
        } finally {
            array_pop($this->stack);
            $this->add($span, $status);
        }
    }

    private function add(array $s, string $status): void
    {
        if (count($this->spans) >= 64) {
            return;
        }$allowed = ['tool', 'channel', 'entity_type', 'entity_id', 'service_identity', 'result', 'route', 'method', 'http_status', 'db_system', 'db_operation', 'statement_fingerprint', 'cache_result', 'job_type', 'query_count', 'db_duration_ms', 'rule_id', 'event_id','request_id','support_reference'];
        $attrs = array_intersect_key($s['attributes'], array_flip($allowed));
        $end = microtime(true);
        $this->spans[] = ['trace_id' => $this->trace, 'span_id' => $s['id'], 'parent_span_id' => $s['parent'], 'name' => mb_substr($s['name'], 0, 150), 'status' => $status, 'duration_ms' => round(($end - $s['start']) * 1000, 3), 'attributes' => json_encode($attrs), 'start_nano' => sprintf('%.0f', $s['start'] * 1e9), 'end_nano' => sprintf('%.0f', $end * 1e9), 'created_at' => now()];
    }

    public function database($event): void
    {
        if (! $this->trace || $this->flushing) {
            return;
        }$this->queries++;
        $this->dbMs += $event->time;
        if (count($this->spans) >= 60 || $event->time < config('platform.slow_query_ms', 100)) {
            return;
        }$this->add(['id' => bin2hex(random_bytes(8)), 'parent' => end($this->stack)['id'] ?? null, 'name' => 'db.slow_query', 'start' => microtime(true) - $event->time / 1000, 'attributes' => ['db_system' => $event->connection->getDriverName(), 'db_operation' => strtoupper(strtok(ltrim($event->sql), ' ')), 'statement_fingerprint' => hash('sha256', $event->sql)]], 'ok');
    }

    public function cache(string $result): void
    {
        if ($this->trace && count($this->spans) < 20) {
            $this->add(['id' => bin2hex(random_bytes(8)), 'parent' => end($this->stack)['id'] ?? null, 'name' => 'cache.'.$result, 'start' => microtime(true), 'attributes' => ['cache_result' => $result]], 'ok');
        }
    }

    public function flush(): void
    {
        if (! $this->trace) {
            return;
        }$this->flushing = true;
        try {
            $company = $this->company ?: Auth::user()?->company_id;
            if ($company && (config('platform.telemetry_enabled') || (Company::find($company)?->platform_settings['observability_enabled'] ?? false))) {
                DB::table('platform_spans')->insert(array_map(fn ($span) => $span + ['company_id' => $company], $this->spans));
            }
        } catch (\Throwable $e) {/* Optional telemetry never blocks business work. */
        } finally {
            $this->flushing = false;
            $this->trace = '';
            $this->spans = [];
            $this->stack = [];
        }
    }

    public function stats(): array
    {
        return ['query_count' => $this->queries, 'db_duration_ms' => round($this->dbMs, 3)];
    }

    public function jobStart(array $ctx, string $name): void
    {
        $owns = ! $this->trace;
        if ($owns) {
            $this->begin($ctx['traceparent'] ?? null, (int) ($ctx['company_id'] ?? 0));
        }$s = ['id' => bin2hex(random_bytes(8)), 'parent' => end($this->stack)['id'] ?? $this->externalParent, 'name' => 'queue.job', 'start' => microtime(true), 'attributes' => ['job_type' => $name]];
        $this->stack[] = $s;
        $this->jobs[] = $owns;
    }

    public function jobEnd(bool $ok): void
    {
        $s = array_pop($this->stack);
        $owns = array_pop($this->jobs);
        if ($s) {
            $this->add($s, $ok ? 'ok' : 'error');
        }if ($owns) {
            $this->flush();
        }
    }

    public function otlp(array $rows): array
    {
        return ['resourceSpans' => [['resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'aims']]]], 'scopeSpans' => [['scope' => ['name' => 'aims.platform', 'version' => '1'], 'spans' => array_map(function ($r) {
            $attrs = json_decode($r->attributes, true) ?? [];
            $a = [];
            foreach ($attrs as $k => $v) {
                $a[] = ['key' => $k, 'value' => ['stringValue' => (string) $v]];
            }

return ['traceId' => $r->trace_id, 'spanId' => $r->span_id, 'parentSpanId' => $r->parent_span_id ?? '', 'name' => $r->name, 'kind' => 1, 'startTimeUnixNano' => $r->start_nano, 'endTimeUnixNano' => $r->end_nano, 'attributes' => $a, 'status' => ['code' => $r->status === 'error' ? 2 : 1]];
        }, $rows)]]]]];
    }
}
