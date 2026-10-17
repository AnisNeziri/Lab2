<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class OnboardingBackup extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['encrypted_contents'];

    protected function casts(): array
    {
        return ['verification' => 'array'];
    }
}
