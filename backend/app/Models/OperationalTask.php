<?php
namespace App\Models;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class OperationalTask extends Model {
    use BelongsToCompany;
    protected $guarded = ['id'];
    protected $attributes = ['status'=>'open','priority'=>'normal'];
    protected function casts(): array { return ['outcome'=>'array','due_at'=>'datetime','completed_at'=>'datetime','escalated_at'=>'datetime']; }
}
