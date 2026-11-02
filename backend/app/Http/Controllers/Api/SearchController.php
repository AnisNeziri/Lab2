<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        private SearchService $searchService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        $exact=$this->searchService->search($validated['q']);
        $hybrid=app(\App\Services\Platform\HybridBusinessSearch::class)->search($validated['q'],$exact);
        $existing=collect($exact)->flatten(1)->pluck('url')->all();
        $extra=collect($hybrid['results'])->reject(fn($r)=>in_array($r['url'],$existing,true))->map(fn($r)=>array_replace($r,['entity_id'=>$r['id'],'id'=>$r['type'].':'.$r['id'],'similar_match'=>$r['match']==='local concept / fuzzy']))->values()->all();
        return response()->json($exact+['business_search'=>$extra]);
    }
}
