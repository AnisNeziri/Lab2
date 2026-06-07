<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class RedisStoreService
{
    private const STATS_TTL = 300;
    private const FEED_MAX = 100;
    private const ALERT_TTL = 3600;

    public function setDashboardStats(int $companyId, array $stats): void
    {
        $this->run(function () use ($companyId, $stats) {
            $key = "dashboard_stats:{$companyId}";
            Redis::hmset($key, $stats);
            Redis::expire($key, self::STATS_TTL);
        });
    }

    public function getDashboardStats(int $companyId): ?array
    {
        return $this->run(function () use ($companyId) {
            $data = Redis::hgetall("dashboard_stats:{$companyId}");

            return empty($data) ? null : $data;
        });
    }

    public function invalidateDashboardStats(int $companyId): void
    {
        $this->run(fn () => Redis::del("dashboard_stats:{$companyId}"));
    }

    public function pushActivityEvent(int $companyId, string $action, string $entity, int $entityId, string $user): void
    {
        $this->run(function () use ($companyId, $action, $entity, $entityId, $user) {
            $key = "activity_feed:{$companyId}";
            $payload = json_encode([
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $entityId,
                'user' => $user,
                'ts' => now()->toISOString(),
            ]);

            Redis::zadd($key, microtime(true), $payload);
            Redis::zremrangebyrank($key, 0, -(self::FEED_MAX + 1));
        });
    }

    public function getRecentActivity(int $companyId, int $limit = 20): array
    {
        return $this->run(function () use ($companyId, $limit) {
            $raw = Redis::zrevrange("activity_feed:{$companyId}", 0, $limit - 1);

            return array_map(fn ($item) => json_decode($item, true), $raw);
        }, []);
    }

    public function setLowStockAlerts(int $companyId, array $productIds): void
    {
        $this->run(function () use ($companyId, $productIds) {
            $key = "low_stock_alerts:{$companyId}";
            Redis::del($key);

            if (! empty($productIds)) {
                Redis::sadd($key, ...$productIds);
                Redis::expire($key, self::ALERT_TTL);
            }
        });
    }

    public function getLowStockAlerts(int $companyId): array
    {
        return $this->run(fn () => Redis::smembers("low_stock_alerts:{$companyId}") ?? [], []);
    }

    public function addLowStockAlert(int $companyId, int $productId): void
    {
        $this->run(function () use ($companyId, $productId) {
            $key = "low_stock_alerts:{$companyId}";
            Redis::sadd($key, $productId);
            Redis::expire($key, self::ALERT_TTL);
        });
    }

    public function removeLowStockAlert(int $companyId, int $productId): void
    {
        $this->run(fn () => Redis::srem("low_stock_alerts:{$companyId}", $productId));
    }

    public function isAvailable(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function run(callable $callback, mixed $default = null): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return $default;
        }
    }
}
