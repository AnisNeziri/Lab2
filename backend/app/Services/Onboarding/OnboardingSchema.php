<?php

namespace App\Services\Onboarding;

use App\Support\Money;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** The one import contract drives mappings, templates and staged validation. */
final class OnboardingSchema
{
    public const CONTRACT = 'dc1-opening-v1';

    public const DOMAINS = [
        'products' => ['external_ref', 'sku', 'name', 'unit', 'barcode', 'category', 'purchase_price', 'selling_price', 'conversion_unit', 'conversion_factor', 'tracking_mode'],
        'customers' => ['external_ref', 'name', 'business_name', 'email', 'phone', 'tax_number', 'address'],
        'suppliers' => ['external_ref', 'name', 'email', 'phone', 'address'],
        'warehouses' => ['external_ref', 'code', 'name', 'address'],
        'locations' => ['external_ref', 'warehouse_ref', 'code', 'name', 'type', 'parent_ref'],
        'inventory' => ['external_ref', 'product_ref', 'warehouse_ref', 'location_ref', 'quantity', 'unit', 'unit_cost', 'value', 'stock_state', 'reference'],
        'receivables' => ['external_ref', 'customer_ref', 'amount', 'currency', 'reference', 'due_date'],
        'customer_advances' => ['external_ref', 'customer_ref', 'amount', 'currency', 'reference'],
        'payables' => ['external_ref', 'supplier_ref', 'amount', 'currency', 'reference', 'due_date'],
        'supplier_advances' => ['external_ref', 'supplier_ref', 'amount', 'currency', 'reference'],
        'cash_bank' => ['external_ref', 'name', 'type', 'currency', 'amount', 'reference'],
    ];

    public const TOTALS = ['inventory_value', 'receivables', 'customer_advances', 'payables', 'supplier_advances', 'cash', 'bank', 'equity'];

    public const MASTERS = ['products', 'customers', 'suppliers', 'warehouses', 'locations'];

    public static function header(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii(trim($value))));
    }

    public static function suggestions(array $headers, string $domain): array
    {
        $aliases = ['sku' => ['sku', 'code', 'kodi', 'productcode'], 'name' => ['name', 'emri', 'artikul', 'artikulli', 'productname'],
            'unit' => ['unit', 'njesia', 'njesi'], 'quantity' => ['quantity', 'sasia', 'qty'], 'unit_cost' => ['unitcost', 'kostonjesi'],
            'amount' => ['amount', 'shuma', 'balance'], 'external_ref' => ['externalref', 'externalid', 'sourceid', 'referenceid'],
            'warehouse_ref' => ['warehouseref', 'depo'], 'customer_ref' => ['customerref', 'klienti'], 'supplier_ref' => ['supplierref', 'furnitori']];
        $result = [];
        foreach (self::DOMAINS[$domain] as $field) {
            $names = array_unique([self::header($field), ...($aliases[$field] ?? [])]);
            $matches = array_values(array_filter($headers, fn ($h) => in_array(self::header($h), $names, true)));
            if (count($matches) === 1) {
                $result[$field] = $matches[0];
            }
        }

        return $result;
    }

    public static function number(mixed $value, string $separator = '.', int $scale = 2, bool $negative = false): string
    {
        $raw = trim((string) $value);
        // Thousands separators/exponent notation are never guessed. Mapping has an explicit decimal convention.
        if ($separator === ',') {
            if (str_contains($raw, '.')) {
                throw ValidationException::withMessages(['number' => 'Remove thousands separators or select the correct decimal convention.']);
            }$raw = str_replace(',', '.', $raw);
        }
        if (! preg_match('/^'.($negative ? '[+-]?' : '\+?').'\d+(?:\.\d{1,'.$scale.'})?$/D', $raw) || strlen($raw) > 20) {
            throw ValidationException::withMessages(['number' => 'Enter a plain decimal with at most '.$scale.' decimal places; no thousands separators.']);
        }
        if (Money::compareDecimal(ltrim($raw, '+-'), '999999999.999999') > 0) {
            throw ValidationException::withMessages(['number' => 'The value exceeds the supported opening limit.']);
        }

        return Money::normalizeDecimal(ltrim($raw, '+'), $scale);
    }

    public static function unit(string $value): string
    {
        $v = Str::lower(trim($value));

        return ['meter' => 'm', 'meters' => 'm', 'metre' => 'm', 'metres' => 'm', 'metra' => 'm', 'piece' => 'pcs', 'pieces' => 'pcs', 'copë' => 'pcs', 'cope' => 'pcs', 'rolls' => 'roll', 'boxes' => 'box', 'kilogram' => 'kg', 'kilograms' => 'kg'][$v] ?? $v;
    }

    public static function template(string $domain): string
    {
        abort_unless(isset(self::DOMAINS[$domain]), 404);
        $examples = [
            'products' => ['P001', 'MIL-04', 'Milano 04', 'm', '', 'Fabrics', '2.50', '5.00', '', '', 'none'],
            'customers' => ['C001', 'Example Customer', '', 'customer@example.test', '', '', ''],
            'suppliers' => ['S001', 'Example Supplier', 'supplier@example.test', '', ''],
            'warehouses' => ['W001', 'MAIN', 'Main warehouse', ''],
            'locations' => ['L001', 'W001', 'A01', 'Zone A01', 'zone', ''],
            'inventory' => ['I001', 'P001', 'W001', '', '12.500', 'm', '2.500000', '31.25', 'available', 'Stock count source'],
            'receivables' => ['AR001', 'C001', '3700.00', 'EUR', 'Legacy statement', ''],
            'customer_advances' => ['CA001', 'C001', '500.00', 'EUR', 'Prepaid opening source'],
            'payables' => ['AP001', 'S001', '25000.00', 'EUR', 'Supplier opening statement', ''],
            'supplier_advances' => ['SA001', 'S001', '6000.00', 'EUR', 'Supplier advance statement'],
            'cash_bank' => ['B001', 'Main bank', 'bank', 'EUR', '10000.00', 'Bank statement at cutoff'],
        ];
        $h = fopen('php://temp', 'r+');
        fputcsv($h, self::DOMAINS[$domain]);
        fputcsv($h, $examples[$domain]);
        rewind($h);
        $s = stream_get_contents($h);
        fclose($h);

        return $s;
    }
}
