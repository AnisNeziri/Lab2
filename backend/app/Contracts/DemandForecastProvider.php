<?php
namespace App\Contracts;
interface DemandForecastProvider {
    public function train(array $dataset, ?array $incumbent = null): array;
    public function predict(array $dataset,array $models):array;
}
