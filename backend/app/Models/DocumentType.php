<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    protected $appends = ['labels'];

    public function getLabelsAttribute(): array
    {
        $sq = ['Purchase Order'=>'Porosi blerjeje','Supplier Invoice'=>'Faturë furnizuesi','Customer Invoice'=>'Faturë klienti','Packing List'=>'Listë paketimi','Bill of Lading'=>'Fletëngarkesë','Customs Declaration'=>'Deklaratë doganore','Certificate'=>'Certifikatë','Quality Evidence'=>'Dëshmi cilësie','Supplier Claim Evidence'=>'Dëshmi e ankesës ndaj furnizuesit','Proof of Delivery'=>'Dëshmi dorëzimi','Contract'=>'Kontratë','Agreement'=>'Marrëveshje','Payment Evidence'=>'Dëshmi pagese','Bank Document'=>'Dokument bankar','Product Specification'=>'Specifikim produkti','Safety/Compliance Document'=>'Dokument sigurie / pajtueshmërie','Return Evidence'=>'Dëshmi kthimi','Internal Document'=>'Dokument i brendshëm','Other'=>'Tjetër'];
        return ['en'=>$this->name,'sq'=>$sq[$this->name]??$this->name];
    }
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requires_review' => 'boolean'];
    }
}
