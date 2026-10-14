<?php

namespace App\Services;

use App\Support\Money;
use Illuminate\Support\Arr;

/** Presentation over already authorized frozen evidence; totals never use a sliced page. */
final class IntelligencePagePresentation
{
    private static function page(array $rows, int $page, int $size, array &$pagination, string $key): array
    {
        $total = count($rows);
        $pagination[$key] = ['current_page' => $page, 'per_page' => $size, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $size))];

        return array_slice(array_values($rows), ($page - 1) * $size, $size);
    }

    public static function finance(array $data, string $view, int $page = 1, int $size = 50): array
    {
        if (($data['state'] ?? null) !== 'ready') {
            return $data + ['view' => $view];
        }
        $result = Arr::except($data, ['evidence', 'forecast', 'evaluation']);
        $e = $data['evidence'];
        $pagination = [];
        $result['evidence'] = Arr::only($e, ['base_currency', 'confidence', 'calculation_version', 'as_of', 'cutoff', 'totals']);
        $result['forecast'] = ['currencies' => []];
        foreach ($data['forecast']['currencies'] ?? [] as $currency => $forecast) {
            $result['forecast']['currencies'][$currency] = $view === 'cash' ? $forecast : Arr::only($forecast, ['opening_recorded_cash', 'inflows', 'outflows', 'expected_closing_cash', 'pressure', 'coverage_complete', 'qualification']);
        }
        switch ($view) {
            case 'overview':
                $result['evidence']['cash'] = Arr::only($e['cash'] ?? [], ['complete']);
                $result['evidence']['inventory'] = Arr::only($e['inventory'] ?? [], ['total', 'currency']);
                break;
            case 'cash':$result['evidence']['cash'] = $e['cash'];
                break;
            case 'receivables':
                $result['evidence']['receivables'] = self::page($e['receivables'] ?? [], $page, $size, $pagination, 'receivables');
                $result['evidence']['customers'] = self::page(array_values(array_filter($e['customers'] ?? [], fn ($r) => (float) ($r['advance'] ?? 0) > 0)), $page, $size, $pagination, 'advances');
                break;
            case 'commitments':
                foreach (['commitments', 'potential'] as $key) {
                    $result['evidence'][$key] = self::page($e[$key] ?? [], $page, $size, $pagination, $key);
                }break;
            case 'inventory':
                $result['evidence']['inventory'] = $e['inventory'];
                $result['evidence']['landed_costs'] = self::page($e['landed_costs'] ?? [], $page, $size, $pagination, 'landed_costs');
                $result['evidence']['inventory']['products'] = self::page($e['inventory']['products'] ?? [], $page, $size, $pagination, 'products');
                break;
            case 'scenarios':$result['evidence'] = Arr::only($e, ['base_currency', 'confidence', 'scenario_options', 'settings']);
                break;
            case 'health':
                $result['evidence'] = Arr::only($e, ['base_currency', 'confidence', 'health', 'working_capital', 'calculation_version', 'qualification', 'limitations', 'settings', 'scenario_options']);
                $result['evidence']['cash'] = Arr::only($e['cash'] ?? [], ['complete']);
                $result['evidence']['commitments'] = self::page($e['commitments'] ?? [], $page, $size, $pagination, 'commitments');
                $result['evidence']['inventory'] = Arr::except($e['inventory'] ?? [], ['products']);
                $result['evaluation'] = Arr::except($data['evaluation'] ?? [], ['collections']);
                if (isset($data['evaluation']['collections'])) {
                    $result['evaluation']['collections'] = self::page($data['evaluation']['collections'], $page, $size, $pagination, 'evaluations');
                }break;
        }

        return $result + ['view' => $view, 'pagination' => $pagination];
    }

    public static function customers(array $data, string $view, int $page = 1, int $size = 50, string $query = '', string $sort = 'value'): array
    {
        if (($data['state'] ?? null) !== 'ready') {
            return $data + ['view' => $view];
        }
        $result = Arr::except($data, ['evidence']);
        $e = $data['evidence'];
        $pagination = [];
        $result['evidence'] = Arr::only($e, ['as_of', 'history_from', 'currency', 'canonical_baskets', 'qualification', 'calculation_version']);
        $profiles = $e['profiles'] ?? [];
        $opportunities = $e['opportunities'] ?? [];
        $result['totals'] = ['customers_with_history' => count(array_filter($profiles, fn ($p) => ($p['purchase_count'] ?? 0) > 0)), 'opportunities' => count($opportunities), 'inactive_customers' => count(array_filter($profiles, fn ($p) => in_array($p['state'], ['AT_RISK', 'INACTIVE'], true)))];
        $needle = mb_strtolower(trim($query));
        $matches = fn ($name) => $needle === '' || str_contains(mb_strtolower($name), $needle);
        $profiles = array_values(array_filter($profiles, fn ($p) => $matches($p['name'])));
        usort($profiles, fn ($a, $b) => $sort === 'name' ? strcmp($a['name'], $b['name']) : Money::compare($b['summary']['revenue'] ?? 0, $a['summary']['revenue'] ?? 0));
        $opportunities = array_values(array_filter($opportunities, fn ($o) => $matches($o['customer']) || $matches($o['product'])));
        switch ($view) {
            case 'overview':
                $result['evidence']['profiles'] = [];
                $result['evidence']['opportunities'] = array_slice($e['opportunities'] ?? [], 0, 4);
                break;
            case 'opportunities':$result['evidence']['opportunities'] = self::page($opportunities, $page, $size, $pagination, 'opportunities');
                break;
            case 'atRisk':$result['evidence']['profiles'] = self::page(array_values(array_filter($profiles, fn ($p) => in_array($p['state'], ['AT_RISK', 'INACTIVE'], true))), $page, $size, $pagination, 'customers');
                break;
            case 'trends':
                $result['evidence']['profiles'] = self::page($profiles, $page, $size, $pagination, 'customers');
                foreach ($e['resource_trends'] ?? [] as $key => $rows) {
                    $result['evidence']['resource_trends'][$key] = self::page(array_values(array_filter($rows, fn ($r) => $matches($r['name']))), $page, $size, $pagination, $key);
                }break;
            case 'affinity':foreach ($e['affinity'] ?? [] as $key => $rows) {
                $result['evidence']['affinity'][$key] = self::page($rows, $page, $size, $pagination, $key);
            }break;
            case 'concentration':
                $result['evidence']['concentration'] = $e['concentration'];
                foreach ($e['concentration']['groups'] ?? [] as $key => $rows) {
                    $result['evidence']['concentration']['groups'][$key] = array_slice($rows,0,20);
                }break;
            case 'health':$result['evidence'] = Arr::only($e,['as_of', 'history_from', 'currency', 'canonical_baskets', 'qualification', 'calculation_version', 'health', 'local_model']);
                break;
        }

        return $result + ['view' => $view, 'pagination' => $pagination];
    }
}
