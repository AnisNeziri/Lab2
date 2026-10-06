<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\SupplyOptimizerService;
use Illuminate\Http\Request;
class SupplyOptimizerController extends Controller {
 public function __construct(private SupplyOptimizerService $s){}
 public function options(){return response()->json($this->s->options());}
 public function index(Request $r){return response()->json($this->s->summary($r->query()));}
 public function show(int $id){return response()->json($this->s->get($id));}
 public function state(int $id){return response()->json($this->s->state($id));}
 public function store(Request $r){return response()->json($this->s->submit($r->all()),202);}
 public function simulate(Request $r,int $id){return response()->json($this->s->simulate($id,$r->all()),202);}
 public function stress(Request $r,int $id){return response()->json($this->s->stress($id,$r->all()));}
 public function explain(Request $r,int $id){return response()->json($this->s->explain($id,$r->integer('product_id')?:null));}
 public function prepare(Request $r,int $id){return response()->json($this->s->prepare($id,$r->all()));}
}
