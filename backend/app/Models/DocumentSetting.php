<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocumentSetting extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $attributes = ['max_file_mb' => 20, 'expiry_notice_days' => 30];

    protected function casts(): array
    {
        return ['allowed_extensions' => 'array'];
    }
}
