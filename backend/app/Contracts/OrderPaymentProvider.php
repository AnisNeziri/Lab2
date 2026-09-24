<?php
namespace App\Contracts;
interface OrderPaymentProvider {
    public function createPayment(string $reference, string $amount, string $currency): array;
    public function verifyPayment(string $reference): array;
    public function capturePayment(string $reference, string $idempotencyKey): array;
    public function refundPayment(string $reference, string $amount, string $idempotencyKey): array;
    public function getStatus(string $reference): array;
}
