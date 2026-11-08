<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\IndexBusinessSearch;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\IntegrationProvider;
use App\Models\ServiceAccount;
use App\Models\WebhookDelivery;
use App\Services\Platform\IntegrationGateway;
use App\Services\Platform\McpToolRegistry;
use App\Services\Platform\PlatformTrace;
use App\Services\Platform\SearchEntityRegistry;
use App\Services\Platform\WebhookContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PlatformController extends Controller
{
    public function show(IntegrationGateway $g)
    {
        $g->permit('integrations.view');
        $company = Auth::user()->company_id;
        $spans = DB::table('platform_spans')->where('company_id', $company);
        $calls = ActivityLog::where('entity', 'ServiceAccount')->where('action', 'like', 'mcp.%');

        return ['webhook_events' => WebhookContract::EVENTS, 'settings' => $g->settings(), 'scopes' => array_keys(IntegrationGateway::SCOPES), 'accounts' => ServiceAccount::latest()->limit(50)->get(), 'mcp' => ['transport' => 'stdio', 'versions' => \App\Services\Platform\McpServer::SUPPORTED_VERSIONS, 'requests' => (clone $calls)->where('created_at', '>', now()->subDay())->count(), 'denied' => (clone $calls)->where('action', 'mcp.request_denied')->where('created_at', '>', now()->subDay())->count(), 'connected_clients' => null, 'note' => 'Stateless adapter: activity is known, persistent connected-client count is unavailable.'], 'search' => ['indexed' => DB::table('business_search_documents')->where('company_id', $company)->count()], 'traces' => (clone $spans)->latest('id')->limit(50)->get(), 'latency' => (clone $spans)->where('created_at', '>', now()->subDay())->selectRaw('name,COUNT(*) as count,AVG(duration_ms) as mean_ms,MAX(duration_ms) as max_ms')->groupBy('name')->get(), 'providers' => IntegrationProvider::where('enabled', true)->get(['id', 'display_name', 'health_state', 'last_success_at', 'last_failure_at']), 'queue' => ['raw_company_backlog_available' => true, 'pending' => DB::table('jobs')->where('company_id', $company)->whereNull('reserved_at')->count(), 'processing' => DB::table('jobs')->where('company_id', $company)->whereNotNull('reserved_at')->count(), 'oldest_created_at' => DB::table('jobs')->where('company_id', $company)->min('created_at'), 'by_queue' => DB::table('jobs')->where('company_id', $company)->selectRaw('queue,COUNT(*) as count')->groupBy('queue')->get(), 'note' => 'Only attributed company jobs are counted. Older unattributed jobs and installation worker health remain in System Integrity; no payloads are returned.'], 'webhook_failures' => WebhookDelivery::whereIn('status', ['failed', 'retrying'])->count()];
    }

    public function settings(Request $r, IntegrationGateway $g)
    {
        $g->permit();
        $v = $r->validate(['api_enabled' => 'required|boolean', 'mcp_enabled' => 'required|boolean', 'observability_enabled' => 'required|boolean']);
        Company::whereKey(Auth::user()->company_id)->update(['platform_settings' => json_encode($v)]);
        ActivityLog::create(['company_id' => Auth::user()->company_id, 'user_id' => Auth::id(), 'action' => 'integration.settings_changed', 'entity' => 'Company', 'entity_id' => Auth::user()->company_id, 'description' => 'Integration access and telemetry policy changed', 'new_value' => $v]);

        return $v;
    }

    public function create(Request $r, IntegrationGateway $g)
    {
        return response()->json($g->credentials($r->all()), 201);
    }

    public function rotate(ServiceAccount $serviceAccount, IntegrationGateway $g)
    {
        return $g->rotate($serviceAccount);
    }

    public function revoke(ServiceAccount $serviceAccount, IntegrationGateway $g)
    {
        return $g->rotate($serviceAccount, true);
    }

    public function export(IntegrationGateway $g)
    {
        $g->permit('integrations.view');
        $rows = DB::table('platform_spans')->where('company_id', Auth::user()->company_id)->latest('id')->limit(500)->get()->all();

        return response()->json(app(PlatformTrace::class)->otlp($rows));
    }

    public function reindex(Request $r, IntegrationGateway $g)
    {
        $g->permit();
        $v = $r->validate(['type' => 'required|in:'.implode(',', array_keys(SearchEntityRegistry::TYPES)), 'after_id' => 'sometimes|integer|min:0']);
        $registry = app(SearchEntityRegistry::class);
        abort_unless($registry->allowed($v['type']), 403);
        $rows = $registry->query($v['type'])->where('id', '>', $v['after_id'] ?? 0)->orderBy('id')->limit(100)->get();
        foreach ($rows as $row) {
            IndexBusinessSearch::dispatch((int) Auth::user()->company_id, $v['type'], (int) $row->id);
        }

return ['queued' => $rows->count(), 'next_after_id' => $rows->last()?->id, 'has_more' => $rows->count() === 100, 'queue' => 'search-index'];
    }

    public function catalog()
    {
        return ['contract' => 'aims.integration.v1', 'tools' => app(McpToolRegistry::class)->catalog()];
    }

    public function call(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:80', 'arguments' => 'present|array']);

        return ['contract' => 'aims.integration.v1', 'data' => app(McpToolRegistry::class)->call($v['name'],$v['arguments'])];
    }
}
