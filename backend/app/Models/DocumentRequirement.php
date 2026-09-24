<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocumentRequirement extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public function type()
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }
}
