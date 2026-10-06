<?php
namespace App\Services;
final class AnalyticsFeatureRegistry {
    public const VERSION='observed-v1';
    public static function definitions():array {
        $groups=[
            'product'=>['sales_7d','sales_30d','sales_90d','revenue_30d','average_daily_demand','available','reserved','incoming','days_of_supply','stockout_days','stockout_count_90d','return_rate','quality_failure_rate','supplier_average_lead_time','supplier_lead_time_variance'],
            'customer'=>['orders_30d','revenue','outstanding','overdue','overdue_ratio','exposure','utilization','average_payment_delay','return_rate'],
            'supplier'=>['average_lead_time','lead_time_variance','delay_variance','on_time_rate','quality_failure_rate','claim_rate','price_movement'],
        ];$result=[];$contracts=[
            'sales_7d'=>'Net canonical base-unit sale quantity, inclusive observation date and preceding 6 days.',
            'sales_30d'=>'Net canonical base-unit sale quantity, inclusive observation date and preceding 29 days.',
            'sales_90d'=>'Net canonical base-unit sale quantity, inclusive observation date and preceding 89 days.',
            'revenue_30d'=>'Company-currency canonical revenue for preceding 30 inclusive days; linked issued invoices replace daily sales, orders excluded.',
            'average_daily_demand'=>'max(0, sales_30d / 30), in inventory units per calendar day.',
            'days_of_supply'=>'Current available inventory / average_daily_demand; null when demand is zero.',
            'stockout_days'=>'Captured daily observations with available <= 0 in trailing 90 days; unsampled days are unknown.',
            'stockout_count_90d'=>'Observed runs of available <= 0 across consecutive captured dates in trailing 90 days; gaps break runs.',
            'return_rate'=>'Recorded returned base-unit quantity / gross sold quantity * 100, trailing 90 days; null when denominator is zero.',
            'orders_30d'=>'Customer order count in the trailing 30 inclusive calendar days, including subsequently cancelled orders.',
            'revenue'=>'Canonical customer revenue in trailing 90 inclusive calendar days.',
            'outstanding'=>'Current debt from CustomerCreditService, not net of advances.',
            'overdue'=>'Current overdue debt from authoritative payment allocation and aging.',
            'overdue_ratio'=>'Overdue debt / current debt * 100; null when debt is zero.',
            'exposure'=>'Authoritative current total credit exposure after available customer advances.',
            'utilization'=>'Authoritative current exposure / configured credit limit * 100; null if no limit.',
            'average_payment_delay'=>'Mean nonnegative due-date to actual paid-at days of fully settled invoices in trailing 90 days; unallocated payments not inferred.',
            'lead_time_variance'=>'Population variance of actual order-to-receipt days observed at capture time.',
            'delay_variance'=>'Population variance of expected-to-receipt day differences observed at capture time.',
            'claim_rate'=>'Recorded supplier claims / non-draft non-cancelled purchase orders * 100.',
            'price_movement'=>'Existing SupplierPerformanceService historical_price_movement_percent.',
        ];
        foreach($groups as $type=>$keys)foreach($keys as $key)$result[]=['key'=>$type.'_'.$key,'name'=>ucwords(str_replace('_',' ',$key)),'description'=>($type==='customer'&&$key==='return_rate'?'Returned sales value / gross sales value * 100 in trailing 90 days; no mixing of product units.':($contracts[$key]??'Authoritative '.$type.' '.$key.' observed at capture time; existing inventory, credit or supplier-scorecard source.')).' Null means unavailable, never zero-imputed.','entity_type'=>$type,'data_type'=>'decimal_nullable','calculation_version'=>self::VERSION,'updated_at'=>'2026-09-28',
            'window'=>$type==='customer'?'last_90_days_and_current_exposure':($type==='supplier'?'history_available_at_observation':'named_window_or_observed_state')];
        return $result;
    }
    public static function features(string $type,array $facts):array {
        $r=[];foreach(self::definitions() as $d)if($d['entity_type']===$type){$field=substr($d['key'],strlen($type)+1);$r[$d['key']]=$facts[$field]??null;}return $r;
    }
}
