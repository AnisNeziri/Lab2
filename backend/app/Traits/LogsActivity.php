<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

trait LogsActivity
{
    protected static function bootLogsActivity()
    {
        static::created(function ($model) {
            static::logActivity('created', $model, null, $model->getAttributes());
        });

        static::updated(function ($model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);
            if ($changes) {
                static::logActivity(
                    'updated',
                    $model,
                    collect(array_keys($changes))->mapWithKeys(fn ($key) => [$key => $model->getRawOriginal($key)])->all(),
                    $changes,
                );
            }
        });

        static::deleted(function ($model) {
            static::logActivity('deleted', $model, $model->getRawOriginal(), $model->getAttributes());
        });
    }

    protected static function logActivity(string $action, $model, ?array $old, ?array $new): void
    {
        $userId = Auth::id();
        $className = class_basename($model);
        $identifier = $model->name ?? $model->sku ?? $model->id;
        $description = "{$className} '{$identifier}' was {$action}.";

        ActivityLog::create([
            'company_id' => $model->company_id ?? Auth::user()?->company_id,
            'user_id' => $userId,
            'action' => strtolower($className).'.'.$action,
            'entity' => $className,
            'entity_id' => $model->getKey(),
            'description' => $description,
            'old_value' => $old ? static::safeAuditValues($old) : null,
            'new_value' => $new ? static::safeAuditValues($new) : null,
            'ip_address' => request()?->ip(),
        ]);
    }

    protected static function safeAuditValues(array $values): array
    {
        foreach (['password', 'remember_token', 'api_token', 'image_data', 'proof_data', 'file_data'] as $sensitive) {
            unset($values[$sensitive]);
        }

        return $values;
    }
}
