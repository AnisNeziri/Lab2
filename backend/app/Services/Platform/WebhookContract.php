<?php

namespace App\Services\Platform;

use App\Models\BusinessEvent;

class WebhookContract
{
    public const EVENTS = ['shipment.created', 'shipment.updated', 'purchase_order.received', 'goods.received', 'customer.payment_received', 'sales_order.confirmed', 'order.created', 'order.updated', 'invoice.posted', 'inventory.low_stock', 'inventory.stockout'];

    public function payload(BusinessEvent $event, int $delivery, string $version): array
    {
        $safe = array_intersect_key($event->metadata ?? [], array_flip(['quantity', 'amount', 'currency', 'status', 'transaction_date', 'product_id', 'purchase_order_id', 'receipt_id', 'warehouse_id', 'available', 'minimum', 'shortage']));
        foreach($safe as $key=>$value){
            $valid=is_scalar($value)&&strlen((string)$value)<=64;
            if(str_ends_with($key,'_id'))$valid=$valid&&filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false;
            elseif(in_array($key,['amount','quantity','available','minimum','shortage'],true))$valid=$valid&&(bool)preg_match('/^-?\d{1,18}(?:\.\d{1,8})?$/',(string)$value);
            elseif($key==='currency')$valid=$valid&&(bool)preg_match('/^[A-Z]{3}$/',(string)$value);
            elseif($key==='status')$valid=$valid&&(bool)preg_match('/^[a-z0-9_]{1,40}$/i',(string)$value);
            elseif($key==='transaction_date')$valid=$valid&&(bool)preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$value);
            if(!$valid)unset($safe[$key]);
        }
        $base = ['event_id' => $event->event_id, 'event_type' => $event->event_type, 'company_id' => $event->company_id, 'entity' => ['type' => $event->entity_type, 'id' => $event->entity_id], 'reference' => mb_substr((string) $event->reference, 0, 160), 'occurred_at' => $event->occurred_at?->toIso8601String(), 'data' => $safe];

        return $version === 'v1' ? ['contract' => 'aims.events.v1', 'delivery_id' => (string) $delivery] + $base : $base;
    }

    public static function verify(string $body, string $timestamp, string $signature, string $secret, ?int $now = null): bool
    {
        return ctype_digit($timestamp) && abs(($now ?? time()) - (int) $timestamp) <= 300 && hash_equals('sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature);
    }
}
