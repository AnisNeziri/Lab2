<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\CustomerSalesIntelligenceService as Service;
use Illuminate\Http\Request;
final class CustomerSalesIntelligenceController extends Controller {
 public function index(Service $s){return $s->latest();}
 public function refresh(Service $s){return $s->refresh();}
 public function customer(int $customer,Service $s){return $s->customer($customer);}
 public function review(int $prediction,Request $r,Service $s){return $s->review($prediction,$r->all());}
 public function draft(int $prediction,Request $r,Service $s){return $s->draft($prediction,$r->all());}
 public function model(Request $r,Service $s){return $s->selectModel($r->all());}
}
