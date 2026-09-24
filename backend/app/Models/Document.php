<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $attributes = ['current_version' => 1, 'confidentiality' => 'internal', 'legal_hold' => false];

    public function currentFile()
    {
        return $this->hasOne(DocumentVersion::class)->ofMany('version', 'max');
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    public function links()
    {
        return $this->hasMany(DocumentLink::class);
    }

    public function type()
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    protected function casts(): array
    {
        return ['current_version' => 'integer', 'tags' => 'array', 'legal_hold' => 'boolean', 'expiry_date' => 'date:Y-m-d', 'retain_until' => 'date:Y-m-d', 'document_date' => 'date:Y-m-d'];
    }
}
