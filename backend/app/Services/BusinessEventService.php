<?php

namespace App\Services;

use App\Jobs\DeliverBusinessEventWebhooks;
use App\Models\BusinessEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BusinessEventService
{
    public function record(
        string $eventType,
        Model $entity,
        ?string $reference = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): ?BusinessEvent {
        try {
            $companyId = (int) ($entity->company_id ?: Auth::user()?->company_id);
            if ($companyId <= 0) {
                return null;
            }

            $entityType = class_basename($entity);
            $key = Str::limit($idempotencyKey ?: implode(':', [
                $eventType,
                $entityType,
                $entity->getKey(),
                $reference ?: 'none',
            ]), 191, '');

            $event = BusinessEvent::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $companyId, 'idempotency_key' => $key],
                [
                    'event_id' => (string) Str::uuid(),
                    'event_type' => $eventType,
                    'entity_type' => $entityType,
                    'entity_id' => $entity->getKey(),
                    'reference' => $reference,
                    'actor_id' => Auth::id(),
                    'occurred_at' => now(),
                    'metadata' => $this->sanitize($metadata),
                ],
            );

            if ($event->wasRecentlyCreated) {
                $dispatch = static fn () => DeliverBusinessEventWebhooks::dispatch($event->id);
                DB::transactionLevel() > 0 ? DB::afterCommit($dispatch) : $dispatch();
            }

            return $event;
        } catch (Throwable $exception) {
            // The event ledger is a secondary reaction. A logging/integration
            // failure must never invalidate the completed domain operation.
            Log::warning('Business event recording failed.', [
                'event_type' => $eventType,
                'entity_type' => class_basename($entity),
                'entity_id' => $entity->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function sanitize(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (preg_match('/secret|password|token|credential|api[_-]?key/i', (string) $key)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }
}
