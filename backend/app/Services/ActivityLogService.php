<?php

namespace App\Services;

use App\Repositories\ActivityLogRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ActivityLogService
{
    public function __construct(private ActivityLogRepository $logs)
    {
    }

    public function list(int $perPage = 25): LengthAwarePaginator
    {
        return $this->logs->paginate($perPage);
    }
}
