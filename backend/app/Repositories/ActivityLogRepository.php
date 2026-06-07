<?php

namespace App\Repositories;

use App\Models\ActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ActivityLogRepository
{
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        return ActivityLog::with('user')->latest('id')->paginate($perPage);
    }
}
