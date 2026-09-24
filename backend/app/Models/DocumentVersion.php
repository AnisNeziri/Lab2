<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocumentVersion extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['storage_key', 'provider'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function approval()
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    protected static function booted(): void
    {
        static::updating(function ($m) {
            foreach (['document_id', 'version', 'filename', 'mime_type', 'size', 'checksum', 'provider', 'storage_key', 'change_note', 'uploaded_by'] as $field) {
                if ($m->isDirty($field)) {
                    throw new \LogicException('Historical document versions are immutable.');
                }
            }
        });
    }
}
