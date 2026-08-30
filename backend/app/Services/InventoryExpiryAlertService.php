<?php

namespace App\Services;

use App\Models\InventoryExpiryAlertState;
use App\Models\InventoryLot;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

class InventoryExpiryAlertService
{
    /**
     * Synchronize one company's durable expiry-alert state.
     *
     * A cleared notification is not recreated while the lot remains in the
     * same warning state. A genuine transition (near-expiry -> expired, or a
     * resolved lot becoming active again) creates one fresh alert.
     */
    public function syncCompany(int $companyId): array
    {
        return DB::transaction(function () use ($companyId): array {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();

            $lots = InventoryLot::withoutGlobalScopes()
                ->with(['product' => fn ($query) => $query->withoutGlobalScopes()])
                ->withSum(['balances as active_quantity' => fn ($query) => $query
                    ->withoutGlobalScopes()->where('quantity', '>', 0)], 'quantity')
                ->where('company_id', $companyId)
                ->whereNotNull('expiry_at')
                ->whereHas('balances', fn ($query) => $query
                    ->withoutGlobalScopes()->where('quantity', '>', 0))
                ->get();

            $activeLotIds = [];
            $created = 0;
            $updated = 0;
            foreach ($lots as $lot) {
                $warningDays = max(0, (int) ($lot->product?->near_expiry_days ?? 30));
                $today = now()->startOfDay();
                $state = $lot->expiry_at->lt($today)
                    ? 'expired'
                    : ($lot->expiry_at->lte($today->copy()->addDays($warningDays)) ? 'near_expiry' : null);
                if (! $state) {
                    continue;
                }

                $activeLotIds[] = $lot->id;
                $alert = InventoryExpiryAlertState::withoutGlobalScopes()->firstOrCreate(
                    ['company_id' => $companyId, 'inventory_lot_id' => $lot->id],
                    ['active_quantity' => 0],
                );
                $alert = InventoryExpiryAlertState::withoutGlobalScopes()->lockForUpdate()->findOrFail($alert->id);
                $quantity = round((float) $lot->active_quantity, 3);
                $transitioned = $alert->notified_state !== $state || $alert->resolved_at !== null;
                $notification = $alert->notification_id
                    ? Notification::withoutGlobalScopes()->find($alert->notification_id)
                    : null;
                $values = $this->notificationValues($lot, $state, $quantity);

                if ($transitioned) {
                    if ($notification) {
                        $notification->update([...$values, 'read_at' => null]);
                        $updated++;
                    } else {
                        $notification = Notification::withoutGlobalScopes()->create($values);
                        $created++;
                    }
                } elseif ($notification) {
                    // Keep quantities and dates current without marking a
                    // previously acknowledged alert unread again.
                    $notification->update($values);
                    $updated++;
                }

                $alert->update([
                    'notification_id' => $notification?->id,
                    'alert_state' => $state,
                    'notified_state' => $state,
                    'active_quantity' => $quantity,
                    'last_seen_at' => now(),
                    'resolved_at' => null,
                ]);
            }

            $resolvedQuery = InventoryExpiryAlertState::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNotNull('alert_state');
            if ($activeLotIds !== []) {
                $resolvedQuery->whereNotIn('inventory_lot_id', $activeLotIds);
            }
            $resolved = 0;
            foreach ($resolvedQuery->lockForUpdate()->get() as $alert) {
                if ($alert->notification_id) {
                    Notification::withoutGlobalScopes()->whereKey($alert->notification_id)
                        ->whereNull('read_at')->update(['read_at' => now()]);
                }
                $alert->update([
                    'alert_state' => null,
                    'notified_state' => null,
                    'active_quantity' => 0,
                    'resolved_at' => now(),
                    'last_seen_at' => now(),
                ]);
                $resolved++;
            }

            return compact('created', 'updated', 'resolved');
        });
    }

    private function notificationValues(InventoryLot $lot, string $state, float $quantity): array
    {
        $expired = $state === 'expired';
        $identity = $lot->serial_number ?: ($lot->lot_number ?: '#'.$lot->id);

        return [
            'company_id' => $lot->company_id,
            'user_id' => null,
            'type' => $expired ? 'inventory_expired' : 'inventory_near_expiry',
            'title' => $expired ? 'Expired inventory' : 'Inventory nearing expiry',
            'message' => sprintf(
                '%s lot %s has %s %s remaining and %s on %s.',
                $lot->product?->name ?? 'Product',
                $identity,
                $quantity,
                $lot->product?->unit ?? 'units',
                $expired ? 'expired' : 'expires',
                $lot->expiry_at->toDateString(),
            ),
            'data' => [
                'inventory_lot_id' => $lot->id,
                'product_id' => $lot->product_id,
                'state' => $state,
                'expiry_at' => $lot->expiry_at->toDateString(),
                'quantity' => $quantity,
            ],
        ];
    }
}
