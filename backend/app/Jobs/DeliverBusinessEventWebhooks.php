<?php

namespace App\Jobs;

use App\Models\BusinessEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

class DeliverBusinessEventWebhooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public readonly int $businessEventId) {}

    public function handle(): void
    {
        $event = BusinessEvent::withoutGlobalScopes()->find($this->businessEventId);
        if (! $event) {
            return;
        }

        $endpoints = WebhookEndpoint::withoutGlobalScopes()
            ->where('company_id', $event->company_id)
            ->where('enabled', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => in_array('*', $endpoint->subscribed_event_types ?? [], true)
                || in_array($event->event_type, $endpoint->subscribed_event_types ?? [], true));

        foreach ($endpoints as $endpoint) {
            $this->deliver($event, $endpoint);
        }
    }

    private function deliver(BusinessEvent $event, WebhookEndpoint $endpoint): void
    {
        $delivery = WebhookDelivery::withoutGlobalScopes()->firstOrCreate(
            ['webhook_endpoint_id' => $endpoint->id, 'business_event_id' => $event->id],
            ['company_id' => $event->company_id, 'status' => 'pending'],
        );
        if ($delivery->status === 'delivered') {
            return;
        }

        $payload = [
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'company_id' => $event->company_id,
            'entity' => ['type' => $event->entity_type, 'id' => $event->entity_id],
            'reference' => $event->reference,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'data' => $event->metadata ?? [],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $attempts = $delivery->attempts + 1;

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-AIMS-Event' => $event->event_type,
                    'X-AIMS-Event-ID' => $event->event_id,
                    'X-AIMS-Signature' => 'sha256='.hash_hmac('sha256', $body, $endpoint->secret),
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->endpoint_url);

            if (! $response->successful()) {
                throw new \RuntimeException('Webhook returned HTTP '.$response->status().'.');
            }

            $delivery->update([
                'status' => 'delivered', 'attempts' => $attempts,
                'response_status' => $response->status(), 'last_error' => null,
                'last_attempt_at' => now(), 'delivered_at' => now(), 'next_retry_at' => null,
            ]);
            $endpoint->update(['last_success_at' => now(), 'last_error' => null]);
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            $delivery->update([
                'status' => 'failed', 'attempts' => $attempts, 'last_error' => $message,
                'last_attempt_at' => now(),
                'next_retry_at' => $attempts < $this->tries
                    ? now()->addSeconds($this->backoff[min($attempts - 1, count($this->backoff) - 1)])
                    : null,
            ]);
            $endpoint->update(['last_failure_at' => now(), 'last_error' => $message]);
            throw $exception;
        }
    }
}
