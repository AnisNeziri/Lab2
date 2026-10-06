<?php
namespace App\Services;
use Carbon\CarbonImmutable as Date;
use Illuminate\Validation\ValidationException;
final class AnalyticsPeriod {
    public static function resolve(array $input=[]): array {
        $today=Date::today(config('app.timezone'));$key=$input['period']??'30d';
        [$from,$to]=match($key){
            'today'=>[$today,$today],'yesterday'=>[$today->subDay(),$today->subDay()],
            '7d'=>[$today->subDays(6),$today],'30d'=>[$today->subDays(29),$today],'90d'=>[$today->subDays(89),$today],
            'month'=>[$today->startOfMonth(),$today],'last_month'=>[$today->subMonthNoOverflow()->startOfMonth(),$today->startOfMonth()->subDay()],
            'quarter'=>[$today->startOfQuarter(),$today],'year'=>[$today->startOfYear(),$today],
            'custom'=>self::custom($input),default=>throw ValidationException::withMessages(['period'=>'Invalid analytics period.']),
        };
        if($from>$to || $to>$today || $from->diffInDays($to)>366)throw ValidationException::withMessages(['period'=>'Choose a past/current range of at most 367 days.']);
        $days=(int)$from->diffInDays($to)+1;
        return ['key'=>$key,'from'=>$from->toDateString(),'to'=>$to->toDateString(),'days'=>$days,'previous_from'=>$from->subDays($days)->toDateString(),'previous_to'=>$from->subDay()->toDateString()];
    }
    private static function custom(array $input): array {
        $v=validator($input,['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d'])->validate();
        return [Date::parse($v['from']),Date::parse($v['to'])];
    }
}
