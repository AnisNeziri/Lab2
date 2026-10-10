<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\{FinancialIntelligenceService,FinancialIntelligenceScenario};
use Illuminate\Http\Request;
final class FinancialIntelligenceController extends Controller {
 public function index(Request $r,FinancialIntelligenceService $s){$v=$r->validate(['horizon'=>'sometimes|in:7,30,60,90','summary_only'=>'sometimes|boolean']);$data=$s->latest((int)($v['horizon']??30));return $r->boolean('summary_only')?\App\Services\WorkspaceSummaryPresentation::finance($data):$data;}
 public function refresh(FinancialIntelligenceService $s){return $s->refresh();}
 public function scenario(Request $r,FinancialIntelligenceScenario $s){return $s->simulate($r->all());}
 public function policy(Request $r,FinancialIntelligenceService $s){return $s->policy($r->all());}
 public function model(Request $r,FinancialIntelligenceService $s){return $s->selectModel($r->all());}
}
