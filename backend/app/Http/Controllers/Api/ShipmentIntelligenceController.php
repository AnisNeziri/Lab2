<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\ShipmentIntelligenceService;
use Illuminate\Http\Request;
final class ShipmentIntelligenceController extends Controller {
    public function index(Request $r,ShipmentIntelligenceService $s){return $s->listing($r->query());}
    public function show(int $shipment,ShipmentIntelligenceService $s){return $s->detail($shipment);}
    public function refresh(int $shipment,ShipmentIntelligenceService $s){return $s->refresh($shipment);}
    public function routes(Request $r,ShipmentIntelligenceService $s){return $s->routes($r->query());}
}
