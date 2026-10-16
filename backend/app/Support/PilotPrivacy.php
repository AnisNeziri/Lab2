<?php

namespace App\Support;

final class PilotPrivacy
{
    public static function text(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
        $text = preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/=-]{8,}/i', '$1[redacted]', $text);
        $text = preg_replace('/\b(password|token|secret|api[_ -]?key)\s*[:=]\s*\S+/i', '$1=[redacted]', $text);
        $text = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email]', $text);

        return mb_substr(trim($text), 0, 2000);
    }

    public static function page(string $input): string
    {
        $path = trim((string) parse_url($input, PHP_URL_PATH), '/');
        if (preg_match('~^control-tower/[0-9]+$~D', $path)) {
            $path = 'control-tower';
        }
        $allowed = ['dashboard', 'products', 'stock', 'categories', 'suppliers', 'reports', 'finance', 'money-accounts', 'accounting', 'system-integrity', 'invoices', 'purchase-orders', 'fulfillment', 'documents', 'procurement', 'quality', 'customer-debts', 'daily-sales', 'shipments/my-shipments', 'shipments/global-map', 'shipments/alerts', 'control-tower', 'users', 'activity-logs', 'cms', 'settings', 'warehouse-operations', 'warehouse-mobile', 'warehouse-layout', 'warehouse-3d', 'operations-center', 'inventory-intelligence', 'inventory-planning', 'financial-intelligence', 'customer-sales-intelligence', 'intelligence-assistant', 'analytics', 'action-center', 'automation-studio', 'decision-learning', 'supply-optimizer', 'strategic-simulation', 'order-hub', 'pilot'];

        return in_array($path, $allowed, true) ? '/'.$path : '/other';
    }
}
