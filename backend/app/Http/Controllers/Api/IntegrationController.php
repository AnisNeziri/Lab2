<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\IntegrationOperationLog;
use App\Models\IntegrationProvider;
use App\Services\EcbFxRateProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    public function health(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $ais = IntegrationProvider::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'category' => 'vessel_tracking', 'provider_key' => 'aisstream'],
            ['display_name' => 'AISStream', 'enabled' => filled(config('tracking.aisstream.api_key')), 'health_state' => 'unknown'],
        );
        $connection = Cache::get('tracking.aisstream.connection');
        if (is_array($connection)) {
            $state = $connection['state'] ?? 'unknown';
            $lastMessage = Cache::get('tracking.aisstream.last_message_at');
            $ais->forceFill([
                'enabled' => filled(config('tracking.aisstream.api_key')),
                'health_state' => in_array($state, ['connected', 'subscribed', 'receiving'], true) ? 'healthy' : ($state === 'error' ? 'error' : 'degraded'),
                'last_data_received_at' => $lastMessage ?: $ais->last_data_received_at,
                'last_error' => $state === 'error' ? ($connection['message'] ?? $ais->last_error) : null,
            ])->save();
        }
        IntegrationProvider::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'category' => 'fx_rates', 'provider_key' => 'ecb'],
            ['display_name' => 'European Central Bank', 'enabled' => true, 'health_state' => 'unknown'],
        );
        $providers = IntegrationProvider::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('category')->orderBy('display_name')->get();

        return response()->json(['data' => $providers->map(fn ($provider) => $this->safe($provider))]);
    }

    public function update(Request $request, IntegrationProvider $integrationProvider): JsonResponse
    {
        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:120'], 'enabled' => ['sometimes', 'boolean'],
            'configuration' => ['sometimes', 'array'], 'credentials' => ['sometimes', 'array'],
            'credential_reference' => ['nullable', 'string', 'max:255'],
        ]);
        $before = $this->safe($integrationProvider);
        $integrationProvider->update($validated);
        ActivityLog::create([
            'company_id' => $integrationProvider->company_id, 'user_id' => $request->user()->id,
            'action' => 'integration.provider.updated', 'entity' => 'IntegrationProvider',
            'entity_id' => $integrationProvider->id, 'description' => 'Integration provider configuration updated.',
            'old_value' => $before, 'new_value' => $this->safe($integrationProvider->fresh()), 'ip_address' => $request->ip(),
        ]);

        return response()->json($this->safe($integrationProvider->fresh()));
    }

    public function operations(Request $request): JsonResponse
    {
        $validated = $request->validate(['provider_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['started', 'succeeded', 'failed'])]]);
        $logs = IntegrationOperationLog::query()->with('provider:id,display_name,provider_key')
            ->when($validated['provider_id'] ?? null, fn ($q, $id) => $q->where('integration_provider_id', $id))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('started_at')->paginate(30);

        return response()->json($logs);
    }

    public function ecb(Request $request, EcbFxRateProvider $ecb): JsonResponse
    {
        $validated = $request->validate([
            'base' => ['required', 'string', 'size:3'], 'quote' => ['required', 'string', 'size:3'],
            'rate_date' => ['nullable', 'date'],
        ]);
        $rate = $ecb->referenceRate($request->user()->company_id, $validated['base'], $validated['quote'], $validated['rate_date'] ?? null);

        return response()->json($rate);
    }

    private function safe(IntegrationProvider $provider): array
    {
        return $provider->only(['id', 'category', 'provider_key', 'display_name', 'enabled', 'health_state', 'last_success_at', 'last_failure_at', 'last_data_received_at', 'last_error']);
    }
}
