<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

trait LogsActivity
{
    protected static function bootLogsActivity()
    {
        static::created(function ($model) {
            static::logActivity('created', $model);
        });

        static::updated(function ($model) {
            static::logActivity('updated', $model);
        });

        static::deleted(function ($model) {
            static::logActivity('deleted', $model);
        });
    }

    protected static function logActivity(string $action, $model)
    {
        $userId = Auth::id();
        $className = class_basename($model);
        $identifier = $model->name ?? $model->sku ?? $model->id;
        $description = "{$className} '{$identifier}' was {$action}.";

        if ($action === 'updated') {
            $changes = $model->getChanges();
            unset($changes['updated_at']);
            if (!empty($changes)) {
                $description .= " Changes: " . json_encode($changes);
            }
        }

        ActivityLog::create([
            'company_id' => Auth::user()?->company_id,
            'user_id' => $userId,
            'action' => "{$className} {$action}",
            'description' => $description,
        ]);
    }
}
