<?php

namespace App\Observers;

use App\Jobs\IndexBusinessSearch;
use App\Services\Platform\SearchEntityRegistry;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class BusinessSearchObserver implements ShouldHandleEventsAfterCommit
{
    public function saved($model): void
    {
        $this->enqueue($model);
    }

    public function deleted($model): void
    {
        $this->enqueue($model);
    }

    private function enqueue($model): void
    {
        try {
            $type = array_search(get_class($model), array_map(fn ($d) => $d[0], SearchEntityRegistry::TYPES), true);
            if ($type !== false && $model->company_id) {
                IndexBusinessSearch::dispatch((int) $model->company_id, $type, (int) $model->id);
            }
        } catch (\Throwable $e) {
            Log::warning('Optional search indexing deferred.', ['entity_type' => class_basename($model)]);
        }
    }
}
