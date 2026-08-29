<?php

namespace App\Console\Commands;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use App\Models\Shipment;
use App\Services\Tracking\AisStreamMessageProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use function Amp\Websocket\Client\connect;

class ConsumeAisStreamCommand extends Command
{
    private const MMSI_LIMIT = 200;

    private const MESSAGE_TYPES = [
        'PositionReport',
        'StandardClassBPositionReport',
        'ExtendedClassBPositionReport',
        'LongRangeAisBroadcastMessage',
        'ShipStaticData',
        'StaticDataReport',
    ];

    protected $signature = 'tracking:aisstream';

    protected $description = 'Consume real AISStream vessel positions for active shipment MMSIs.';

    public function handle(AisStreamMessageProcessor $processor): int
    {
        $key = trim((string) config('tracking.aisstream.api_key'));
        if ($key === '') {
            $this->setConnectionState('not_configured');
            $this->error('AISSTREAM_API_KEY is not configured.');

            return self::FAILURE;
        }

        if (! config('tracking.external_enabled') || config('tracking.vessel_provider') !== 'aisstream') {
            $this->setConnectionState('disabled');
            $this->warn('AISStream tracking is disabled on this installation.');

            return self::SUCCESS;
        }

        $lockHandle = $this->acquireProcessLock();
        if (! $lockHandle) {
            $this->warn('An AISStream worker is already running for this AIMS installation.');

            return self::SUCCESS;
        }

        try {
            return $this->consume($processor, $key);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function consume(AisStreamMessageProcessor $processor, string $key): int
    {
        $retrySeconds = 5;

        while (true) {
            $connection = null;

            try {
                $selection = $this->activeMmsis();
                if ($selection['total'] === 0) {
                    $this->setConnectionState('idle', [], [
                        'message' => 'Waiting for an active AIS vessel MMSI.',
                    ]);
                    sleep(5);

                    continue;
                }

                $mmsis = $selection['mmsis'];
                $this->reportOverflow($selection['overflow']);
                $this->setConnectionState('connecting', $mmsis, [
                    'overflow_count' => $selection['overflow'],
                ]);
                Log::info('AISStream connecting.', [
                    'tracked_mmsi_count' => count($mmsis),
                    'overflow_count' => $selection['overflow'],
                ]);

                $connection = connect((string) config('tracking.aisstream.url'));
                $this->sendSubscription($connection, $key, $mmsis);
                $subscriptionConfirmed = false;

                while (true) {
                    try {
                        // This timeout is only a quiet-period checkpoint. It
                        // must not tear down a healthy long-lived WebSocket.
                        $message = $connection->receive(new TimeoutCancellation(5));
                    } catch (CancelledException) {
                        $selection = $this->activeMmsis();
                        if ($selection['total'] === 0) {
                            $this->setConnectionState('idle', [], [
                                'message' => 'Waiting for an active AIS vessel MMSI.',
                            ]);
                            break;
                        }

                        $this->reportOverflow($selection['overflow']);
                        if ($selection['mmsis'] !== $mmsis) {
                            $mmsis = $selection['mmsis'];
                            $subscriptionConfirmed = false;
                            $this->setConnectionState('updating_subscription', $mmsis, [
                                'overflow_count' => $selection['overflow'],
                            ]);
                            $this->sendSubscription($connection, $key, $mmsis);
                            Log::info('AISStream subscription replaced after the tracked vessel set changed.', [
                                'tracked_mmsi_count' => count($mmsis),
                                'overflow_count' => $selection['overflow'],
                            ]);
                        } else {
                            $this->setConnectionState($subscriptionConfirmed ? 'connected' : 'connecting', $mmsis, [
                                'overflow_count' => $selection['overflow'],
                            ]);
                        }

                        continue;
                    }

                    if ($message === null) {
                        throw new \RuntimeException('AISStream closed the WebSocket connection.');
                    }

                    try {
                        $payload = json_decode($message->buffer(), true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $exception) {
                        Log::warning('AISStream sent a malformed JSON frame; the frame was ignored.', [
                            'message' => $exception->getMessage(),
                        ]);

                        continue;
                    }

                    if (! is_array($payload)) {
                        Log::warning('AISStream sent a non-object frame; the frame was ignored.');

                        continue;
                    }

                    if (isset($payload['error'])) {
                        throw new \RuntimeException('AISStream rejected the subscription: '.(string) $payload['error']);
                    }

                    if (($payload['MessageType'] ?? null) === 'SubscriptionConfirmation') {
                        $subscriptionConfirmed = true;
                        $retrySeconds = 5;
                        $this->setConnectionState('connected', $mmsis, [
                            'connected_at' => now()->toIso8601String(),
                            'compression_enabled' => (bool) data_get($payload, 'Message.CompressionEnabled', false),
                            'overflow_count' => $selection['overflow'],
                        ]);
                        Cache::put('tracking.aisstream.connected_at', now(), now()->addDay());
                        Log::info('AISStream subscription confirmed.', [
                            'tracked_mmsi_count' => count($mmsis),
                            'compression_enabled' => (bool) data_get($payload, 'Message.CompressionEnabled', false),
                        ]);

                        continue;
                    }

                    try {
                        $updated = $processor->process($payload);
                        if ($updated > 0) {
                            Log::debug('AISStream message persisted and available to clients.', [
                                'message_type' => $payload['MessageType'] ?? null,
                                'matched_shipments' => $updated,
                            ]);
                        }
                    } catch (\Throwable $exception) {
                        // One bad payload or one shipment must not terminate
                        // the shared stream for every other tracked vessel.
                        Log::warning('AISStream message processing failed; listening continues.', [
                            'message_type' => $payload['MessageType'] ?? null,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            } catch (\Throwable $exception) {
                $this->setConnectionState('error', [], [
                    'message' => $this->safeConnectionMessage($exception),
                    'retry_in_seconds' => $retrySeconds,
                ]);
                Log::warning('AISStream connection lost; reconnecting with backoff.', [
                    'message' => $exception->getMessage(),
                    'retry_in_seconds' => $retrySeconds,
                ]);

                // AISStream recommends exponential backoff with jitter. Keep
                // the maximum below one minute so shutdown stays responsive.
                $jitterMilliseconds = random_int(100, 1000);
                usleep((($retrySeconds * 1000) + $jitterMilliseconds) * 1000);
                $retrySeconds = min(45, $retrySeconds * 2);
            } finally {
                $connection?->close();
            }
        }
    }

    /**
     * @return array{mmsis: array<int, string>, total: int, overflow: int}
     */
    private function activeMmsis(): array
    {
        $all = Shipment::withoutGlobalScopes()
            ->active()
            ->where('tracking_provider', 'aisstream')
            ->where(fn ($query) => $query->where('transport_mode', 'sea')->orWhereNull('transport_mode'))
            ->whereNotNull('mmsi')
            ->whereNotIn('status', ['delivered'])
            ->orderByRaw('position_updated_at is null desc')
            ->orderBy('position_updated_at')
            ->orderBy('id')
            ->pluck('mmsi')
            ->map(fn ($id) => (string) $id)
            ->filter(fn ($id) => preg_match('/^\d{9}$/', $id) === 1)
            ->unique()
            ->values();
        $limit = self::MMSI_LIMIT;
        $offset = 0;
        if ($all->count() > $limit) {
            $rotationSeconds = max(60, (int) config('tracking.subscription_rotation_seconds', 300));
            $bucketCount = (int) ceil($all->count() / $limit);
            $bucket = intdiv(time(), $rotationSeconds) % $bucketCount;
            $offset = $bucket * $limit;
        }

        return [
            'mmsis' => $all->slice($offset, $limit)->values()->all(),
            'total' => $all->count(),
            'overflow' => max(0, $all->count() - $limit),
        ];
    }

    private function sendSubscription(object $connection, string $key, array $mmsis): void
    {
        $connection->sendText(json_encode([
            'APIKey' => $key,
            // A China-Albania vessel crosses several regions during one trip,
            // so a vessel-filtered global bounding box is intentional.
            'BoundingBoxes' => [[[-90, -180], [90, 180]]],
            'FiltersShipMMSI' => array_values($mmsis),
            'FilterMessageTypes' => self::MESSAGE_TYPES,
        ], JSON_THROW_ON_ERROR));
    }

    private function setConnectionState(string $state, array $mmsis = [], array $extra = []): void
    {
        $previous = Cache::get('tracking.aisstream.connection', []);
        $payload = array_merge(is_array($previous) ? $previous : [], $extra, [
            'state' => $state,
            'tracked_mmsis_count' => count($mmsis),
            'updated_at' => now()->toIso8601String(),
        ]);

        if (! in_array($state, ['error', 'idle'], true)) {
            unset($payload['message'], $payload['retry_in_seconds']);
        }

        // Never cache or return the API key. The MMSI list is unnecessary for
        // status reporting, so only retain its count.
        unset($payload['mmsis'], $payload['api_key'], $payload['APIKey']);
        Cache::put('tracking.aisstream.connection', $payload, now()->addDay());
    }

    private function reportOverflow(int $overflow): void
    {
        if ($overflow <= 0) {
            return;
        }

        Log::warning('AISStream subscription limit reached; some tracked vessels are queued.', [
            'subscription_limit' => self::MMSI_LIMIT,
            'queued_count' => $overflow,
        ]);
    }

    private function safeConnectionMessage(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message === '' ? 'AISStream connection unavailable.' : Str::limit($message, 240, '…');
    }

    /** @return resource|null */
    private function acquireProcessLock()
    {
        $path = storage_path('framework/aisstream-worker.lock');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $handle = fopen($path, 'c+');
        if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return null;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        return $handle;
    }
}
