<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => WebhookEndpoint::query()->withCount('deliveries')->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $endpoint = WebhookEndpoint::create([
            'contract_version'=>'v1', ...$validated, 'enabled'=>$validated['enabled']??false, 'company_id' => $request->user()->company_id,
            'secret' => $validated['secret'] ?? Str::random(64),
        ]);
        $this->audit($request, $endpoint, 'webhook.created', 'Webhook endpoint created.');

        return response()->json($endpoint->toArray()+['secret'=>$endpoint->secret,'shown_once'=>true], 201);
    }

    public function update(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $validated = $this->validated($request, true);
        if (blank($validated['secret'] ?? null)) unset($validated['secret']);
        $rotated=isset($validated['secret']);
        $webhookEndpoint->update($validated);
        $this->audit($request, $webhookEndpoint, 'webhook.updated', 'Webhook endpoint updated.');

        return response()->json($webhookEndpoint->fresh()->toArray()+($rotated?['secret'=>$webhookEndpoint->secret,'shown_once'=>true]:[]));
    }

    public function destroy(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->audit($request, $webhookEndpoint, 'webhook.deleted', 'Webhook endpoint deleted.');
        $webhookEndpoint->delete();

        return response()->json(null, 204);
    }

    public function deliveries(WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        return response()->json($webhookEndpoint->deliveries()->with('event:id,event_id,event_type,reference,occurred_at')->latest()->paginate(30));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'endpoint_url' => [$required, 'url', 'starts_with:https://', 'max:1000', function($field,$value,$fail){$u=parse_url($value);if(isset($u['user'])||isset($u['pass'])||isset($u['fragment'])||isset($u['query']))$fail('Use an HTTPS URL without credentials, query strings or fragments.');}],
            'enabled' => ['sometimes', 'boolean'], 'secret' => ['nullable', 'string', 'min:24', 'max:255'],
            'subscribed_event_types' => [$required, 'array', 'min:1', 'max:100'],
            'subscribed_event_types.*' => ['string', \Illuminate\Validation\Rule::in([...\App\Services\Platform\WebhookContract::EVENTS,'*'])],
            'contract_version'=>['sometimes','in:v1,legacy'],
        ]);
    }

    private function audit(Request $request, WebhookEndpoint $endpoint, string $action, string $description): void
    {
        ActivityLog::create([
            'company_id' => $endpoint->company_id, 'user_id' => $request->user()->id,
            'action' => $action, 'entity' => 'WebhookEndpoint', 'entity_id' => $endpoint->id,
            'description' => $description, 'new_value' => $endpoint->only(['name', 'endpoint_url', 'enabled', 'subscribed_event_types']),
            'ip_address' => $request->ip(),
        ]);
    }
}
