<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocumentLink extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
