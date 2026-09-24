<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderChannelKey extends Model {
    protected $guarded=['id'];
    protected $hidden=['token_hash','webhook_secret'];
    protected function casts():array{return ['scopes'=>'array','webhook_secret'=>'encrypted','expires_at'=>'datetime','revoked_at'=>'datetime'];}
}
