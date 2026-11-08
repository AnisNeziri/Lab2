<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AssistantVisualSearch;
use Illuminate\Http\Request;

final class AssistantMediaController extends Controller
{
    public function screenshot(Request $r,AssistantVisualSearch $s){
        abort_unless(auth()->user()?->company_id,403);
        $r->validate(['image'=>'required|file|max:3906','language'=>'sometimes|in:en,sq','question'=>'sometimes|string|max:600']);
        $d=$s->describe(base64_encode($r->file('image')->get()),false);
        return response()->json(app(\App\Services\LocalAssistantProvider::class)->screenshot($d['preview'],$r->input('language','en'),$r->input('question','Describe the visible AIMS problem.'))+['retained_upload'=>false,'qualification'=>'Unverified visual observation. No server cause has been verified. If unavailable, describe the visible error in your support report.']);
    }
    public function search(Request $r,AssistantVisualSearch $s){
        $r->validate(['image'=>'required|file|max:3906']);
        return response()->json($s->search($r->file('image')));
    }
}
