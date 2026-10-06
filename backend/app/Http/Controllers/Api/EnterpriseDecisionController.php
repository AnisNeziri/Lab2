<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\EnterpriseDecisionService;
use Illuminate\Http\Request;
class EnterpriseDecisionController extends Controller {
 public function index(Request $r,EnterpriseDecisionService $s){return $s->listing($r->query());}
 public function show(EnterpriseDecisionService $s,int $decision){return $s->detail($decision);}
 public function refresh(Request $r,EnterpriseDecisionService $s,int $product){$v=$r->validate(['warehouse_id'=>'nullable|integer|min:1']);return $s->refresh($product,$v['warehouse_id']??null);}
 public function feedback(Request $r,EnterpriseDecisionService $s,int $decision){return $s->feedback($decision,$r->all());}
 public function review(Request $r,EnterpriseDecisionService $s,int $decision){return $s->review($decision,$r->all());}
 public function draft(Request $r,EnterpriseDecisionService $s,int $decision){return $s->draft($decision,$r->all());}
 public function simulate(Request $r,EnterpriseDecisionService $s,int $decision){return $s->simulate($decision,$r->all());}
}
