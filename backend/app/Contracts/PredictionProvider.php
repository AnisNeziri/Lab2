<?php
namespace App\Contracts;
/** Future local models implement this interface; no model is invoked in this phase. */
interface PredictionProvider {
    public function modelKey(): string;
    public function modelVersion(): string;
    public function predict(\App\Models\AnalyticsSnapshot $input): array;
}
