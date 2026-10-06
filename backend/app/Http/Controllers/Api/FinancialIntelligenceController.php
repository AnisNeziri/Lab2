<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\{FinancialIntelligenceService,FinancialIntelligenceScenario};
use Illuminate\Http\Request;
final class FinancialIntelligenceController extends Controller {
 public function index(Request $r,FinancialIntelligenceService $s){$v=$r->validate(['horizon'=>'sometimes|in:7,30,60,90']);return $s->latest((int)($v['horizon']??30));}
 public function refresh(FinancialIntelligenceService $s){return $s->refresh();}
 public function scenario(Request $r,FinancialIntelligenceScenario $s){return $s->simulate($r->all());}
 public function policy(Request $r,FinancialIntelligenceService $s){return $s->policy($r->all());}
 public function model(Request $r,FinancialIntelligenceService $s){return $s->selectModel($r->all());}
}
