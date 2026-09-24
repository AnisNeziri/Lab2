<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRun extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'operation', 'backup_type', 'status', 'modules',
        'size_bytes', 'verification_result', 'error_summary', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
