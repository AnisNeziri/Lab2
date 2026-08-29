<?php

namespace Tests\Feature;

use App\Services\RedisStoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedisCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_redis_service_is_available_or_graceful(): void
    {
        $service = app(RedisStoreService::class);

        $this->assertFalse($service->getRecentActivity(1, 5) === null);
    }

    public function test_offline_mode_never_requires_redis(): void
    {
        config()->set('system.operation_mode', 'offline');

        $service = app(RedisStoreService::class);

        $this->assertFalse($service->isAvailable());
        $this->assertSame([], $service->getRecentActivity(1, 5));
        $this->assertSame([], $service->getLowStockAlerts(1));
        $this->assertNull($service->getDashboardStats(1));
    }

    public function test_dashboard_stats_round_trip_when_redis_up(): void
    {
        $service = app(RedisStoreService::class);

        if (! $service->isAvailable()) {
            $this->markTestSkipped('Start Redis locally to run this test.');
        }

        $this->actingAsApiUser();
        $service->setDashboardStats($this->apiCompany->id, ['total_products' => 3]);
        $stats = $service->getDashboardStats($this->apiCompany->id);

        $this->assertSame('3', $stats['total_products'] ?? null);
    }
}
