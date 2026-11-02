<?php

namespace App\Services\Platform;

use App\Contracts\BusinessEmbeddingProvider;
use Illuminate\Support\Facades\DB;

class BusinessSearchIndexer
{
    public function index(int $company, string $type, int $id): void
    {
        $definition = SearchEntityRegistry::TYPES[$type] ?? null;
        if (! $definition) {
            return;
        }
        // Reload current source on delivery; an old queued event cannot restore stale/deleted text.
        $row = $definition[0]::withoutGlobalScopes()->where('company_id', $company)->find($id);
        $key = ['company_id' => $company, 'entity_type' => $type, 'entity_id' => $id];
        if (! $row || ($row->deleted_at ?? null) || ($row->archived_at ?? null)) {
            DB::table('business_search_documents')->where($key)->delete();

            return;
        }
        $doc = app(SearchEntityRegistry::class)->normalize($type, $row);
        $provider = app(BusinessEmbeddingProvider::class);
        $values=$key+['title' => $doc['title'], 'identifier' => $doc['identifier'], 'search_text' => $doc['text'], 'embedding' => json_encode($provider->embed($doc['text'])), 'model_version' => $provider->version(), 'index_version' => 'business-v1', 'source_updated_at' => $row->updated_at, 'updated_at' => now(), 'created_at' => now()];
        DB::table('business_search_documents')->upsert([$values],array_keys($key),['title','identifier','search_text','embedding','model_version','index_version','source_updated_at','updated_at']);
    }
}
