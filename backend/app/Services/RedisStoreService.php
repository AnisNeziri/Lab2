<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Uses Redis as a NoSQL data store (not just cache) for three collections:
 *  - dashboard_stats:{company_id}  — Hash with live KPI counters
 *  - activity_feed:{company_id}    — Sorted set (score = timestamp) of recent actions
 *  - low_stock_alerts:{company_id} — Set of product IDs currently below min_quantity
 */
class RedisStoreService
{
    private const STATS_TTL    = 300;   // 5 min
    private const FEED_MAX     = 100;   // keep last 100 events per company
    private const ALERT_TTL    = 3600;  // 1 hour

    // ─── Dashboard Stats ────────────────────────────────────────────────────

    public function setDashboardStats(int $companyId, array $stats): void
    {
        $key = "dashboard_stats:{$companyId}";
        Redis::hmset($key, $stats);
        Redis::expire($key, self::STATS_TTL);
    }

    public function getDashboardStats(int $companyId): ?array
    {
        $key  = "dashboard_stats:{$companyId}";
        $data = Redis::hgetall($key);

        return empty($data) ? null : $data;
    }

    public function invalidateDashboardStats(int $companyId): void
    {
        Redis::del("dashboard_stats:{$companyId}");
    }

    // ─── Activity Feed ──────────────────────────────────────────────────────

    public function pushActivityEvent(int $companyId, string $action, string $entity, int $entityId, string $user): void
    {
        $key     = "activity_feed:{$companyId}";
        $score   = microtime(true);
        $payload = json_encode([
            'action'    => $action,
            'entity'    => $entity,
            'entity_id' => $entityId,
            'user'      => $user,
            'ts'        => now()->toISOString(),
        ]);

        Redis::zadd($key, $score, $payload);
        // Keep only the latest N events
        Redis::zremrangebyrank($key, 0, -(self::FEED_MAX + 1));
    }

    public function getRecentActivity(int $companyId, int $limit = 20): array
    {
        $key  = "activity_feed:{$companyId}";
        $raw  = Redis::zrevrange($key, 0, $limit - 1);

        return array_map(fn ($item) => json_decode($item, true), $raw);
    }

    // ─── Low-Stock Alerts ───────────────────────────────────────────────────

    public function setLowStockAlerts(int $companyId, array $productIds): void
    {
        $key = "low_stock_alerts:{$companyId}";
        Redis::del($key);

        if (! empty($productIds)) {
            Redis::sadd($key, ...$productIds);
            Redis::expire($key, self::ALERT_TTL);
        }
    }

    public function getLowStockAlerts(int $companyId): array
    {
        return Redis::smembers("low_stock_alerts:{$companyId}") ?? [];
    }

    public function addLowStockAlert(int $companyId, int $productId): void
    {
        $key = "low_stock_alerts:{$companyId}";
        Redis::sadd($key, $productId);
        Redis::expire($key, self::ALERT_TTL);
    }

    public function removeLowStockAlert(int $companyId, int $productId): void
    {
        Redis::srem("low_stock_alerts:{$companyId}", $productId);
    }
}
