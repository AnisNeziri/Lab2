<?php
namespace App\Contracts;
/** Adapters normalize untrusted input; they never mutate stock or financial state. */
interface OrderChannelProvider {
    public function pullOrders(?string $cursor, int $limit): array;
    public function receiveWebhook(array $payload): array;
    public function acknowledgeOrder(string $reference): void;
    public function updateOrderStatus(string $reference, array $status): void;
    public function pushInventory(array $availability): void;
    public function pushPrice(array $prices): void;
    public function pushTracking(string $reference, array $tracking): void;
    public function healthCheck(): array;
}
