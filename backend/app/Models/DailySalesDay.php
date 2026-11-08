<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DailySalesDay extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'sale_date', 'notes', 'closed_at', 'closed_by', 'closed_sale_count', 'closed_last_sale_id'];

    protected function casts(): array
    {
        return ['sale_date' => 'date', 'closed_at'=>'datetime', 'closed_sale_count'=>'integer'];
    }

    public function covers(\Illuminate\Support\Collection $sales): bool
    {
        return $this->closed_at && $sales->isNotEmpty()
            && $this->closed_sale_count === $sales->count()
            && (int)$this->closed_last_sale_id === (int)$sales->max('id')
            && $sales->every(fn($sale)=>$sale->status==='finalized');
    }
}
