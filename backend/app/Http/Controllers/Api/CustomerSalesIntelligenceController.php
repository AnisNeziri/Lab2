<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\CustomerSalesIntelligenceService as Service;
use Illuminate\Http\Request;
final class CustomerSalesIntelligenceController extends Controller {
 public function index(Request $r,Service $s){$r->validate(['summary_only'=>'sometimes|boolean']);$data=$s->latest();return $r->boolean('summary_only')?\App\Services\WorkspaceSummaryPresentation::customers($data):$data;}
 public function refresh(Service $s){return $s->refresh();}
 public function customer(int $customer,Service $s){return $s->customer($customer);}
 public function review(int $prediction,Request $r,Service $s){return $s->review($prediction,$r->all());}
 public function draft(int $prediction,Request $r,Service $s){return $s->draft($prediction,$r->all());}
 public function model(Request $r,Service $s){return $s->selectModel($r->all());}
}
