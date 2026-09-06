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
            ...$validated, 'company_id' => $request->user()->company_id,
            'secret' => $validated['secret'] ?? Str::random(64),
        ]);
        $this->audit($request, $endpoint, 'webhook.created', 'Webhook endpoint created.');

        return response()->json($endpoint, 201);
    }

    public function update(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $validated = $this->validated($request, true);
        if (blank($validated['secret'] ?? null)) unset($validated['secret']);
        $webhookEndpoint->update($validated);
        $this->audit($request, $webhookEndpoint, 'webhook.updated', 'Webhook endpoint updated.');

        return response()->json($webhookEndpoint->fresh());
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
            'endpoint_url' => [$required, 'url', 'starts_with:https://', 'max:1000'],
            'enabled' => ['sometimes', 'boolean'], 'secret' => ['nullable', 'string', 'min:24', 'max:255'],
            'subscribed_event_types' => [$required, 'array', 'min:1', 'max:100'],
            'subscribed_event_types.*' => ['string', 'max:100'],
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
