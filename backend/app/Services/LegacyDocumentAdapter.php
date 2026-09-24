<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Expense;
use App\Models\QualityAttachment;
use App\Models\ShipmentDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Deliberate, verified copy-in. Originals and their API contracts remain intact. */
class LegacyDocumentAdapter
{
    public function listing(): array
    {
        $service = app(DocumentService::class);
        $service->permit('documents.manage');
        $items = [];
        foreach (['quality' => [QualityAttachment::class, 'quality.view'], 'shipment' => [ShipmentDocument::class, 'shipments.view'], 'expense' => [Expense::class, 'expenses.manage']] as $source => [$class,$permission]) {
            if (! $service->can($permission)) {
                continue;
            }$imported = Document::where('legacy_key', 'like', $source.':%')->pluck('legacy_key')->map(fn ($key) => (int) substr($key, strlen($source) + 1))->all();
            $q = $class::query()->whereNull('document_version_id')->whereNotIn('id', $imported);
            $columns = $source === 'expense' ? ['id', 'proof_filename'] : ['id', 'filename'];
            if ($source === 'expense') {
                $q->whereNotNull('proof_filename');
            }
            foreach ($q->latest('id')->limit(25)->get($columns) as $r) {
                $items[] = ['source' => $source, 'source_id' => $r->id, 'filename' => $r->filename ?? $r->proof_filename];
            }
        }

return $items;
    }

    public function import(string $source, int $id): array
    {
        $service = app(DocumentService::class);
        $service->permit('documents.manage');

        return DB::transaction(function () use ($service, $source, $id) {
            Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();
            $key = $source.':'.$id;
            $existing = Document::where('legacy_key', $key)->first();
            if ($existing) {
                return $service->detail($existing);
            }
            $links = [];
            switch ($source) {
                case 'quality':$row = QualityAttachment::findOrFail($id);
                    $bytes = base64_decode($row->getRawOriginal('file_data'), true);
                    $name = $row->filename;
                    $hash = $row->sha256;
                    $type = 'Quality Evidence';
                    if ($row->quality_inspection_id) {
                        $links[] = ['quality-inspection', $row->quality_inspection_id];
                    }if ($row->supplier_claim_id) {
                        $links[] = ['supplier-claim', $row->supplier_claim_id];
                    }break;
                case 'shipment':$row = ShipmentDocument::findOrFail($id);
                    $bytes = base64_decode($row->getRawOriginal('file_data'), true);
                    $name = $row->filename;
                    $hash = $row->sha256;
                    $type = 'Other';
                    $links[] = ['shipment', $row->shipment_id];
                    break;
                case 'expense':$row = Expense::findOrFail($id);
                    $bytes = $row->proof_data;
                    $name = $row->proof_filename;
                    $hash = $row->proof_sha256;
                    $type = 'Payment Evidence';
                    $links[] = ['expense', $row->id];
                    break;
                default:abort(422, 'Unsupported legacy evidence source.');
            }
            abort_unless(is_string($bytes) && $bytes !== '' && is_string($name) && $hash && hash_equals($hash, hash('sha256', $bytes)), 409, 'Legacy evidence is missing or its checksum does not match. It was not migrated.');
            foreach ($links as [$entity,$target]) {
                app(DocumentEntityRegistry::class)->resolve($entity, (int) $target);
            }
            $type = DocumentType::firstOrCreate(['company_id' => Auth::user()->company_id, 'name' => $type]);
            $path = tempnam(sys_get_temp_dir(), 'aims-document-');
            try {
                file_put_contents($path, $bytes);
                $result = $service->upload(new UploadedFile($path, $name, null, null, true), ['title' => $name, 'document_type_id' => $type->id, 'allow_duplicate' => true, 'description' => 'Imported from existing '.$source.' evidence #'.$id]);
                $d = Document::findOrFail($result['id']);
                $d->update(['legacy_key' => $key]);
                foreach ($links as [$entity,$target]) {
                    $service->link($d, $entity, (int) $target);
                }$service->event($d, 'legacy_imported', ['source' => $source, 'source_id' => $id, 'checksum' => $hash]);

                return $service->detail($d);
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        });
    }
}
