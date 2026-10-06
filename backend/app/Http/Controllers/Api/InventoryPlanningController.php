<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\InventoryPlanningService;
use Illuminate\Http\Request;
class InventoryPlanningController extends Controller {
 public function index(Request $r,InventoryPlanningService $s){return $s->listing($r->only('q','warehouse_id','view'));}
 public function show(Request $r,InventoryPlanningService $s,int $product){return $s->view($product,$r->query());}
 public function policy(Request $r,InventoryPlanningService $s,int $product){return $s->policy($product,$r->all());}
 public function save(Request $r,InventoryPlanningService $s,int $product){return $s->save($product,$r->all());}
 public function scenarios(Request $r,InventoryPlanningService $s,int $product){return $s->scenarios($product,$r->all());}
 public function consolidate(Request $r,InventoryPlanningService $s){return $s->consolidate($r->all());}
 public function draft(Request $r,InventoryPlanningService $s){return $s->draft($r->all());}
 public function feedback(Request $r,InventoryPlanningService $s,int $recommendation){return $s->feedback($recommendation,$r->all());}
}
