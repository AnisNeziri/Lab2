<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\CustomerSalesIntelligenceService as Service;
use Illuminate\Http\Request;
final class CustomerSalesIntelligenceController extends Controller {
 public function index(Request $r,Service $s){$v=$r->validate(['summary_only'=>'sometimes|boolean','view'=>'sometimes|in:overview,opportunities,atRisk,trends,affinity,concentration,health','page'=>'sometimes|integer|min:1','per_page'=>'sometimes|integer|min:1|max:100','q'=>'sometimes|nullable|string|max:100','sort'=>'sometimes|in:name,value']);$data=$s->latest();if(isset($v['view']))return \App\Services\IntelligencePagePresentation::customers($data,$v['view'],(int)($v['page']??1),(int)($v['per_page']??50),$v['q']??'',$v['sort']??'value');return $r->boolean('summary_only')?\App\Services\WorkspaceSummaryPresentation::customers($data):$data;}
 public function refresh(Service $s){return $s->refresh();}
 public function customer(int $customer,Service $s){return $s->customer($customer);}
 public function review(int $prediction,Request $r,Service $s){return $s->review($prediction,$r->all());}
 public function draft(int $prediction,Request $r,Service $s){return $s->draft($prediction,$r->all());}
 public function model(Request $r,Service $s){return $s->selectModel($r->all());}
}
