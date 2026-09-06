<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class QualityAttachment extends Model
{
    use BelongsToCompany;
    protected $fillable = ['company_id','quality_inspection_id','supplier_claim_id','document_type','filename','mime_type','file_size','sha256','file_data','uploaded_by'];
    protected $hidden = ['file_data'];
    protected function casts(): array { return ['file_size'=>'integer']; }
}
