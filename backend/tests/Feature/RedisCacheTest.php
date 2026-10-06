<?php

namespace Tests\Feature;

use App\Services\RedisStoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
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
        Redis::shouldReceive('connection')->never();

        $service = app(RedisStoreService::class);

        $this->assertFalse($service->isAvailable());
        $this->assertSame([], $service->getRecentActivity(1, 5));
        $this->assertSame([], $service->getLowStockAlerts(1));
        $this->assertNull($service->getDashboardStats(1));
        $this->assertSame('disabled_offline', $service->status()['status']);
    }

    public function test_optional_redis_can_be_disabled_without_a_connection(): void
    {
        config(['system.operation_mode' => 'online', 'system.redis_enabled' => false]);
        Redis::shouldReceive('connection')->never();
        $service = app(RedisStoreService::class);
        $this->assertSame('disabled', $service->status()['status']);
        $this->assertSame([], $service->getRecentActivity(1));
    }

    public function test_redis_connection_failure_does_not_change_operation_mode(): void
    {
        $this->actingAsApiUser('admin');
        config(['system.operation_mode' => 'online', 'system.redis_enabled' => true, 'cache.default' => 'file']);
        Redis::shouldReceive('connection')->andThrow(new \RuntimeException('Connection refused'));
        $this->getJson('/api/system/mode')->assertOk()
            ->assertJsonPath('mode', 'online')
            ->assertJsonPath('online', true)
            ->assertJsonPath('redis.status', 'unavailable')
            ->assertJsonPath('redis.required', false);
        $this->assertSame([], app(RedisStoreService::class)->getRecentActivity(1));
        $this->getJson('/api/dashboard')->assertOk();
    }

    public function test_connected_redis_is_reported(): void
    {
        config(['system.operation_mode' => 'online', 'system.redis_enabled' => true, 'cache.default' => 'file']);
        Redis::shouldReceive('connection->ping')->once()->andReturn('PONG');
        $this->assertSame('connected', app(RedisStoreService::class)->status()['status']);
    }

    public function test_status_endpoints_survive_a_failed_cache(): void
    {
        $this->actingAsApiUser('superadmin');
        config(['system.operation_mode' => 'offline']);
        Redis::shouldReceive('connection')->never();
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('Cache unavailable'));
        $this->getJson('/api/system/mode')->assertOk()
            ->assertJsonPath('mode', 'offline')
            ->assertJsonPath('redis.status', 'disabled_offline')
            ->assertJsonPath('cache_available', false);
        $this->getJson('/api/superadmin/health')->assertOk()
            ->assertJsonPath('database', 'online')
            ->assertJsonPath('redis.status', 'disabled_offline')
            ->assertJsonPath('cache_available', false);
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
