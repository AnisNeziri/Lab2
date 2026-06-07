<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ActivityLogService
{
    public function list(int $perPage = 25): LengthAwarePaginator
    {
        return ActivityLog::with('user')
            ->latest('id')
            ->paginate($perPage);
    }
}
