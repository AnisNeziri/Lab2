<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentDocument extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'shipment_id', 'document_type', 'filename', 'mime_type',
        'file_size', 'sha256', 'file_data', 'uploaded_by',
    ];

    protected $hidden = ['file_data'];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by')->withTrashed(); }
}
