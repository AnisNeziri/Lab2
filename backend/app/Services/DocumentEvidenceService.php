<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

class DocumentEvidenceService
{
    public function store(UploadedFile $file, string $type, array $links): DocumentVersion
    {
        $service = app(DocumentService::class);
        $category = DocumentType::firstOrCreate(['company_id' => Auth::user()->company_id, 'name' => $type]);
        $result = $service->upload($file, ['title' => $file->getClientOriginalName(), 'document_type_id' => $category->id, 'allow_duplicate' => true]);
        $document = Document::findOrFail($result['id']);
        foreach ($links as [$entity,$id]) {
            $service->link($document, $entity, (int) $id);
        }

        return $document->versions()->where('version', 1)->firstOrFail();
    }

    public function download(int $versionId)
    {
        $v = DocumentVersion::findOrFail($versionId);

        return app(DocumentService::class)->download($v->document, $v->version);
    }
}
