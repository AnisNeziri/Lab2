<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\DecisionLearningService;
use Illuminate\Http\Request;
class DecisionLearningController extends Controller {
 public function __construct(private DecisionLearningService $service){}
 public function index(Request $r){return response()->json($this->service->summary($r->query()));}
 public function performance(Request $r){return response()->json($this->service->performance($r->query()));}
 public function outcome(int $id){return response()->json($this->service->outcome($id));}
 public function recordOutcome(int $id){return response()->json($this->service->recordOutcome($id));}
 public function experiment(Request $r){return response()->json($this->service->createExperiment($r->all()));}
 public function compare(int $id){return response()->json($this->service->comparison($id));}
 public function promote(Request $r,int $id){return response()->json($this->service->changePolicy($id,'promote',$r->all()));}
 public function rollback(Request $r,int $id){return response()->json($this->service->changePolicy($id,'rollback',$r->all()));}
}
