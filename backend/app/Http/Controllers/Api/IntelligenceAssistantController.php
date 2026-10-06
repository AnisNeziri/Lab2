<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IntelligenceAssistantService;
use Illuminate\Http\Request;

final class IntelligenceAssistantController extends Controller
{
    public function ask(Request $r,IntelligenceAssistantService $s){return response()->json($s->ask($r->all()));}
    public function status(IntelligenceAssistantService $s){return response()->json($s->status());}
    public function confirm(Request $r,IntelligenceAssistantService $s){return response()->json($s->confirm($r->all()));}
    public function feedback(Request $r,IntelligenceAssistantService $s){return response()->json($s->feedback($r->all()));}
    public function usage(IntelligenceAssistantService $s){return response()->json($s->usage());}
}
