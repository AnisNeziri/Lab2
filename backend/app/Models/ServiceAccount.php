<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ServiceAccount extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'expires_at' => 'datetime', 'last_used_at' => 'datetime', 'rotated_at' => 'datetime'];
    }
}
