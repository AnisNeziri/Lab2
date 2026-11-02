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

    public function __construct(public readonly int $businessEventId, public readonly ?int $endpointId = null) { $this->onConnection('database')->onQueue('integrations'); }

    public function handle(): void
    {
        $event = BusinessEvent::withoutGlobalScopes()->find($this->businessEventId);
        if (! $event || !in_array($event->event_type, \App\Services\Platform\WebhookContract::EVENTS, true)) {
            return;
        }
        $entitlement = app(\App\Services\Commercial\Entitlements::class)->state($event->company_id);
        if (!in_array('integrations', $entitlement['capabilities'], true) || !$entitlement['write_allowed']) return;

        WebhookDelivery::withoutGlobalScopes()->where('company_id',$event->company_id)->where('business_event_id',$event->id)->whereIn('status',['pending','failed','retrying'])->whereHas('endpoint',fn($q)=>$q->withoutGlobalScopes()->where('enabled',false))->update(['status'=>'disabled','next_retry_at'=>null]);
        $endpoints = WebhookEndpoint::withoutGlobalScopes()
            ->where('company_id', $event->company_id)
            ->where('enabled', true)
            ->when($this->endpointId,fn($q)=>$q->whereKey($this->endpointId))
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => in_array('*', $endpoint->subscribed_event_types ?? [], true)
                || in_array($event->event_type, $endpoint->subscribed_event_types ?? [], true));

        $failed=false;
        foreach ($endpoints as $endpoint) {
            try { if($this->endpointId) $this->deliver($event,$endpoint); else self::dispatch($event->id,$endpoint->id); } catch (Throwable $e) { $failed=true; }
        }
        if($failed) throw new \RuntimeException('Webhook delivery requires bounded retry.');
    }

    private function deliver(BusinessEvent $event, WebhookEndpoint $endpoint): void
    {
        $delivery = WebhookDelivery::withoutGlobalScopes()->firstOrCreate(
            ['webhook_endpoint_id' => $endpoint->id, 'business_event_id' => $event->id],
            ['company_id' => $event->company_id, 'status' => 'pending'],
        );
        $lock=\Illuminate\Support\Facades\Cache::lock('webhook-delivery:'.$delivery->id,30);
        if(!$lock->get()) return;
        try {
        $delivery->refresh();$endpoint->refresh();
        if(!$endpoint->enabled){$delivery->update(['status'=>'disabled','next_retry_at'=>null]);return;}
        if($delivery->status==='delivered'||$delivery->attempts>=$this->tries||($delivery->attempts>0&&$delivery->status==='failed'&&!$delivery->next_retry_at)||$delivery->next_retry_at?->isFuture())return;
        $body=$delivery->payload_body;
        if(!$body){$body=json_encode(app(\App\Services\Platform\WebhookContract::class)->payload($event,$delivery->id,$endpoint->contract_version??'legacy'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$delivery->update(['payload_body'=>$body]);}
        $timestamp=(string)time();
        $attempts = $delivery->attempts + 1;

        try {
            $addresses=app(\App\Services\Platform\WebhookNetworkPolicy::class)->addresses($endpoint->endpoint_url);
            $host=parse_url($endpoint->endpoint_url,PHP_URL_HOST);$port=parse_url($endpoint->endpoint_url,PHP_URL_PORT)?:443;
            $response = app(\App\Services\Platform\PlatformTrace::class)->measure('webhook.delivery',fn()=>Http::timeout(10)->withOptions(['allow_redirects'=>false,'curl'=>[CURLOPT_RESOLVE=>[$host.':'.$port.':'.$addresses[0]]]])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-AIMS-Event' => $event->event_type,
                    'X-AIMS-Event-ID' => $event->event_id,
                    'X-AIMS-Timestamp'=>$timestamp,'X-AIMS-Delivery-ID'=>(string)$delivery->id,
                    'X-AIMS-Signature' => 'sha256='.hash_hmac('sha256', $endpoint->contract_version==='v1'?$timestamp.'.'.$body:$body, $endpoint->secret),
                    'X-AIMS-Signature-V2'=>'sha256='.hash_hmac('sha256',$timestamp.'.'.$body,$endpoint->secret),
                    'traceparent'=>app(\App\Services\Platform\PlatformTrace::class)->context()['traceparent']??'',
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->endpoint_url),['event_id'=>$event->event_id]);

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
            $message = 'Webhook delivery failed. Verify endpoint availability and signing configuration.';
            $delivery->update([
                'status' => 'failed', 'attempts' => $attempts, 'last_error' => $message,
                'last_attempt_at' => now(),
                'next_retry_at' => $attempts < $this->tries && (!isset($response)||$response->serverError()||$response->status()===429)
                    ? now()->addSeconds($this->backoff[min($attempts - 1, count($this->backoff) - 1)])
                    : null,
            ]);
            $endpoint->update(['last_failure_at' => now(), 'last_error' => $message]);
            if($delivery->next_retry_at) throw new \RuntimeException('Webhook delivery retry pending.');
        }
        } finally { $lock->release(); }
    }
}
