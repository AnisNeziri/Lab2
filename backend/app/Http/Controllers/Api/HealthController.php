<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RedisStoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function show(RedisStoreService $redis): JsonResponse
    {
        $database = $this->databaseStatus();
        $cacheAvailable = true;
        try {
            $connection = Cache::get('tracking.aisstream.connection', []);
            $lastMessage = Cache::get('tracking.aisstream.last_message_at');
        } catch (\Throwable) {
            $connection = [];
            $lastMessage = null;
            $cacheAvailable = false;
        }
        $connection = is_array($connection) ? $connection : [];
        $connectionUpdatedAt = ! empty($connection['updated_at']) ? Carbon::parse($connection['updated_at']) : null;
        $lastMessageAt = $lastMessage ? Carbon::parse($lastMessage) : null;
        $connectionState = ! $cacheAvailable ? 'cache_unavailable' : ($connectionUpdatedAt?->gte(now()->subMinutes(2))
            ? ($connection['state'] ?? 'unknown')
            : 'worker_unavailable');

        return response()->json([
            'database' => $database,
            'mode' => config('system.operation_mode', 'online'),
            'redis' => $redis->status(),
            'cache_available' => $cacheAvailable,
            'cache_driver' => config('cache.default'),
            'scheduler' => 'Configure the Laravel scheduler on the host to run every minute.',
            'aisstream' => [
                'status' => $connectionState,
                'connection_updated_at' => $connectionUpdatedAt?->toIso8601String(),
                'last_message_at' => $lastMessageAt?->toIso8601String(),
                'tracked_mmsis_count' => (int) ($connection['tracked_mmsis_count'] ?? 0),
                'note' => $connectionState === 'worker_unavailable'
                    ? 'Start php artisan tracking:aisstream. The worker connects after an active MMSI is added.'
                    : null,
            ],
        ], $database === 'online' ? 200 : 503);
    }

    private function databaseStatus(): string
    {
        try {
            DB::select('select 1');

            return 'online';
        } catch (\Throwable) {
            return 'offline';
        }
    }
}
