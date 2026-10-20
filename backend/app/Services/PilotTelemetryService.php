<?php

namespace App\Services;

use App\Models\PilotProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PilotTelemetryService
{
    public const BUCKETS = ['under_100' => 100, 'under_300' => 300, 'under_500' => 500, 'under_1000' => 1000, 'under_3000' => 3000, 'under_10000' => 10000, 'over_10000' => null];

    public function enabled(): bool
    {
        $company = Auth::user()?->company_id;
        if (! $company || ! app()->environment('production') || (int) config('pilot.company_id') !== (int) $company) {
            return false;
        }

        return (bool) Cache::remember('pilot-profile:'.$company, 60, function () {
            $profile = PilotProfile::first();

            return $profile?->status === 'active' && $profile->telemetry_enabled && app(PilotService::class)->source()['real_data_attested'];
        });
    }

    public function visit(): void
    {
        if (! $this->enabled()) {
            return;
        }
        DB::table('pilot_daily_users')->insertOrIgnore(['company_id' => Auth::user()->company_id, 'user_id' => Auth::id(), 'role' => Auth::user()->role, 'day' => $this->day()]);
    }

    public function record(Request $request, Response $response, float $started): void
    {
        try {
            if (! $this->enabled() || ! $request->is('api/*') || $request->is('api/pilot/*')) {
                return;
            }
            $ms = max(0, min(3600000, (int) round((microtime(true) - $started) * 1000)));
            $dimensions = ['company_id' => Auth::user()->company_id, 'day' => $this->day(),
                'route_key' => substr($request->route()?->uri() ?: 'api/unmatched', 0, 100), 'method' => $request->method(), 'role' => Auth::user()->role, 'http_status' => $response->getStatusCode()];
            $bucket = 'over_10000';
            foreach (self::BUCKETS as $name => $upper) {
                if ($upper !== null && $ms < $upper) {
                    $bucket = $name;
                    break;
                }
            }
            // No response body inspection: streamed payloads remain streamed and their size stays unknown.
            $length = (int) $response->headers->get('Content-Length', 0);
            DB::table('pilot_request_aggregates')->insertOrIgnore($dimensions);
            DB::table('pilot_request_aggregates')->where($dimensions)->update([
                'requests' => DB::raw('requests + 1'), 'duration_ms' => DB::raw('duration_ms + '.$ms),
                'max_ms' => DB::raw('CASE WHEN max_ms < '.$ms.' THEN '.$ms.' ELSE max_ms END'),
                'response_bytes' => DB::raw('response_bytes + '.max(0, $length)), $bucket => DB::raw($bucket.' + 1'),
            ]);
        } catch (\Throwable) {
            Log::warning('Pilot aggregate unavailable.', ['code' => 'PILOT_METRICS_UNAVAILABLE']);
        }
    }

    private function day(): string
    {
        return now(PilotProfile::value('timezone') ?: 'UTC')->toDateString();
    }

    public function summary(?string $from, string $to): array
    {
        $company = Auth::user()->company_id;
        $q = DB::table('pilot_request_aggregates')->where('company_id', $company)->whereBetween('day', [$from ?: $to, $to]);
        $totals = (clone $q)->select('route_key', 'method')->selectRaw('SUM(requests) as samples, MAX(max_ms) as max_ms, SUM(CASE WHEN http_status >= 400 THEN requests ELSE 0 END) as failed_requests, SUM(response_bytes) as known_response_bytes');
        foreach (self::BUCKETS as $name => $_) {
            $totals->selectRaw('SUM('.$name.') as '.$name);
        }
        $rows = $totals->groupBy('route_key', 'method')->orderByDesc('samples')->limit(50)->get()->map(function ($row) {
            $data = (array) $row;
            $data['p50_upper_ms'] = $this->percentile($data, .50, 20);
            $data['p95_upper_ms'] = $this->percentile($data, .95, 100);
            foreach (self::BUCKETS as $key => $_) {
                unset($data[$key]);
            }

            return $data;
        });

        return ['enabled' => $this->enabled(), 'daily_active_users' => DB::table('pilot_daily_users')->where('company_id', $company)->whereBetween('day', [$from ?: $to, $to])->select('day', 'role')->selectRaw('COUNT(*) as users')->groupBy('day', 'role')->orderBy('day')->get(),
            'endpoints' => $rows, 'basis' => 'Opt-in aggregates; active users require a visible page navigation signal. Percentiles are histogram upper bounds (p50 >=20, p95 >=100 samples); the open tail is unknown. Payload sizes include Content-Length only. No keystrokes, bodies, queries or navigation trails are captured.'];
    }

    private function percentile(array $row, float $p, int $minimum): ?int
    {
        if ($row['samples'] < $minimum) {
            return null;
        }
        $target = (int) ceil($row['samples'] * $p);
        $count = 0;
        foreach (self::BUCKETS as $key => $upper) {
            $count += (int) $row[$key];
            if ($count >= $target) {
                return $upper;
            }
        }

        return null;
    }

    public function prune(): void
    {
        // CLI runs only for the explicitly selected company; other tenants are never swept.
        if (! $company = config('pilot.company_id')) {
            return;
        }
        foreach (['pilot_daily_users', 'pilot_request_aggregates'] as $table) {
            DB::table($table)->where('company_id', $company)->where('day', '<', today()->subDays(config('pilot.metric_retention_days'))->toDateString())->delete();
        }
        DB::table('pilot_incidents')->where('company_id', $company)->where('occurred_at', '<', now()->subDays(config('pilot.diagnostic_retention_days')))->delete();
    }
}
