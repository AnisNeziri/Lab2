<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IntelligenceAssistantService;
use Illuminate\Http\Request;

final class IntelligenceAssistantController extends Controller
{
    public function supportPreview(Request $r,\App\Services\AssistantSupport $s){return response()->json($s->prepare($r->all()));}
    public function supportSubmit(string $id,Request $r,\App\Services\AssistantSupport $s){return response()->json($s->submit($id,$r->all()));}
    public function supportStatus(int $id,\App\Services\AssistantSupport $s){return response()->json($s->status($id));}
    public function ask(Request $r,IntelligenceAssistantService $s){return response()->json($s->ask($r->all()));}
    public function status(IntelligenceAssistantService $s){return response()->json($s->status());}
    public function confirm(Request $r,IntelligenceAssistantService $s){return response()->json($s->confirm($r->all()));}
    public function feedback(Request $r,IntelligenceAssistantService $s){return response()->json($s->feedback($r->all()));}
    public function usage(IntelligenceAssistantService $s){return response()->json($s->usage());}
}
