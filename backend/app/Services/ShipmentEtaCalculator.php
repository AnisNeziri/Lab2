<?php
namespace App\Services;
use Carbon\CarbonImmutable as Date;

/** Interpretable local statistics, not a fabricated ML model. No domain writes. */
final class ShipmentEtaCalculator {
    public function distribution(array $observations, string $field): array {
        $valid=array_values(array_filter($observations,fn($r)=>isset($r[$field])&&$r[$field]>=0&&($r['known_at']??'')<=now()->toIso8601String()&&($r['actual_arrival']??'')<=now()->toDateString()));
        $values=array_column($valid,$field); sort($values); $n=count($values);
        $q=fn($p)=>$n?round((float)$values[(int)round(($n-1)*$p)],1):null;
        return ['samples'=>$n,'qualified'=>$n>=config('shipment_intelligence.minimum_route_samples'),
            'median_days'=>$q(.5),'p10_days'=>$q(.1),'p90_days'=>$q(.9),
            'observation_ids'=>array_column($valid,'shipment_id'),
            'qualification'=>'descriptive_history_not_a_calibrated_interval'];
    }

    public function predict(array $facts, array $route): array {
        $actual=$facts['warehouse_actual']??null;
        $carrier=$facts['carrier_eta']??null; $operational=$facts['operational_eta']??null;
        $warehouseSchedule=$facts['warehouse_schedule']??null;
        $transit=$route['transit']; $whole=$route['warehouse']; $tail=$route['port_to_warehouse'];
        $expected=null; $from=null; $to=null; $source='unavailable'; $target='warehouse'; $confidence='limited'; $assumptions=[];
        if ($actual) { $expected=$from=$to=$actual; $source='actual_warehouse_arrival'; }
        elseif ($warehouseSchedule) { $expected=$from=$to=$warehouseSchedule; $source='recorded_warehouse_schedule'; $assumptions[]='schedule_not_statistical_confidence_interval'; }
        elseif (($facts['departure']??null)&&$whole['qualified']) {
            $expected=$this->day($facts['departure'],$whole['median_days']);
            $from=$this->day($facts['departure'],$whole['p10_days']); $to=$this->day($facts['departure'],$whole['p90_days']);
            $source='historical_departure_to_warehouse'; $confidence='moderate';
        } else {
            $port=$carrier??$operational;
            if ($port) { $expected=$from=$to=$port; $source=$carrier?'carrier_schedule':'operational_schedule'; $target=$facts['schedule_target']??'port'; $assumptions[]='schedule_not_statistical_confidence_interval'; }
            if (($facts['departure']??null)&&$transit['qualified']) {
                $hist=$this->day($facts['departure'],$transit['median_days']);
                // A recorded promise is never overwritten. The separately stored
                // estimate conservatively accounts for slower genuine route history.
                $expected=$expected?max($expected,$hist):$hist;
                $from=$this->day($facts['departure'],$transit['p10_days']);
                $to=max($expected,$this->day($facts['departure'],$transit['p90_days']));
                $source='historical_route_with_recorded_schedule'; $target='port'; $confidence='moderate';
            }
            if (($facts['port_actual']??null)) { $expected=$from=$to=$facts['port_actual']; $source='actual_port_arrival'; $target='port'; }
            if ($expected&&$target==='port'&&$tail['qualified']) {
                $from=$this->day($from,$tail['p10_days']); $to=$this->day($to,$tail['p90_days']);
                $expected=$this->day($expected,$tail['median_days']); $target='warehouse'; $source.='_plus_observed_inland_receiving';
            } elseif ($expected&&$target==='port') $assumptions[]='customs_inland_and_receiving_duration_unknown';
        }
        if(!$actual&&($facts['port_actual']??null)&&$target==='warehouse'&&$tail['qualified']){
            $expected=$this->day($facts['port_actual'],$tail['median_days']);$from=$this->day($facts['port_actual'],$tail['p10_days']);$to=$this->day($facts['port_actual'],$tail['p90_days']);$source='actual_port_arrival_plus_observed_inland_receiving';
        }
        $missed=null;
        if (!$actual&&$expected&&$expected<today()->toDateString()&&!(($facts['port_actual']??null)&&$target==='port')) {
            // An overdue promise is not silently rolled forward and presented as a
            // predicted date. Show its missed date separately, pending new evidence.
            $assumptions[]='expected_date_passed_without_arrival';
            $missed=$expected;$expected=$from=$to=null;$confidence='limited';
        }
        $baseline=$target==='warehouse'?($facts['warehouse_schedule']??null):($carrier??$operational);
        $baselineSource='recorded_same_target_schedule';$distribution=$target==='warehouse'?$whole:$transit;
        if(($facts['departure']??null)&&$distribution['qualified']){$baseline=$this->day($facts['departure'],$distribution['median_days']);$baselineSource='historical_route_median';}
        return ['carrier'=>$carrier,'operational'=>$operational,'predicted'=>$expected,'range_start'=>$from,'range_end'=>$to,
            'target'=>$target,'actual_port'=>$facts['port_actual']??null,'actual_warehouse'=>$actual,'source'=>$source,'missed_estimate'=>$missed,
            'baseline'=>$baseline,'baseline_source'=>$baselineSource,
            'confidence'=>$confidence,'model_version'=>'route-median-v1:'.substr(hash('sha256',json_encode($route)),0,12),
            'history_samples'=>max($transit['samples'],$whole['samples']), 'assumptions'=>$assumptions,
            'limited_historical_evidence'=>!$whole['qualified']&&!$transit['qualified']];
    }
    private function day(string $date, float $days): string { return Date::parse($date)->addDays((int)ceil($days))->toDateString(); }

    public function attribution(array $milestones): array {
        $categories=['supplier_confirmation'=>'SUPPLIER_PREPARATION','supplier_production'=>'SUPPLIER_PREPARATION',
            'cargo_ready'=>'SUPPLIER_PREPARATION','supplier_dispatch'=>'SUPPLIER_PREPARATION','container_booking'=>'ORIGIN_LOGISTICS','container_loaded'=>'ORIGIN_LOGISTICS',
            'origin_port'=>'ORIGIN_LOGISTICS','vessel_departure'=>'ORIGIN_LOGISTICS','sea_transit'=>'INTERNATIONAL_TRANSIT',
            'transshipment'=>'TRANSSHIPMENT','destination_port'=>'INTERNATIONAL_TRANSIT','customs_started'=>'CUSTOMS','customs_cleared'=>'CUSTOMS',
            'inland_transport'=>'INLAND_TRANSPORT','warehouse_arrival'=>'INLAND_TRANSPORT','goods_receipt'=>'RECEIVING','inventory_available'=>'RECEIVING'];
        $rows=[];
        foreach ($milestones as $m) {
            $planned=$m['planned']??null; $actual=$m['actual']??null;
            if (!$planned||($actual&&$actual>now()->toIso8601String())) continue;
            $end=$actual??now()->toIso8601String();
            $days=Date::parse($planned)->diffInDays(Date::parse($end),false);
            if ($days<1) continue;
            $rows[]=['milestone'=>$m['type'],'category'=>$categories[$m['type']]??'UNKNOWN','late_days'=>round($days,1),
                'planned'=>$planned,'actual'=>$actual,'source'=>$m['source']??'recorded','qualification'=>'stage_schedule_deviation_not_proven_cause'];
        }
        return $rows?:[['category'=>'UNKNOWN','late_days'=>null,'qualification'=>'no_attributable_milestone_evidence']];
    }
}
