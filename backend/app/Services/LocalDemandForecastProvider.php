<?php
namespace App\Services;
use App\Contracts\DemandForecastProvider;
use App\Exceptions\ForecastUnavailableException;
use Symfony\Component\Process\Process;
final class LocalDemandForecastProvider implements DemandForecastProvider {
    public function train(array $dataset, ?array $incumbent=null):array {
        return $this->run(['series'=>$dataset['series'],'incumbent'=>$incumbent,'mode'=>$dataset['mode']??'train']);
    }
    public function predict(array $dataset,array $models):array {
        return $this->run(['series'=>$dataset['series'],'models'=>$models,'mode'=>'predict']);
    }
    private function run(array $payload):array {
        // Executable/script are operator configuration, never supplied by an API caller.
        $p=new Process([(string)config('inventory_intelligence.python'),'-I',base_path('ml/forecast.py')]);
        $p->setTimeout((int)config('inventory_intelligence.timeout_seconds',45));
        $p->setInput(json_encode($payload,JSON_THROW_ON_ERROR));
        try{$p->run();}catch(\Symfony\Component\Process\Exception\ExceptionInterface $e){throw new ForecastUnavailableException('Local forecasting could not start or exceeded its time limit. Ordinary AIMS operations are unaffected.',0,$e);}
        if(!$p->isSuccessful())throw new ForecastUnavailableException('Local forecasting is unavailable. Configure AIMS_ML_PYTHON with a Python 3.10+ executable. Ordinary AIMS operations are unaffected.');
        try{$result=json_decode($p->getOutput(),true,64,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new ForecastUnavailableException('Invalid local forecast response.',0,$e);}
        if(!is_array($result)||!in_array($result['status']??null,['ready','insufficient_data'],true))throw new ForecastUnavailableException('Invalid local forecast response.');
        return $result;
    }
}
