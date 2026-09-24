<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Services\DocumentEntityRegistry;
use App\Services\DocumentService;
use App\Services\LegacyDocumentAdapter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    public function __construct(private DocumentService $service, private DocumentEntityRegistry $entities) {}

    private function fields(): array
    {
        return ['title' => ['sometimes', 'required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'document_type_id' => ['sometimes', 'required', 'integer'], 'reference' => ['nullable', 'string', 'max:255'], 'issuer' => ['nullable', 'string', 'max:255'], 'document_date' => ['nullable', 'date'], 'expiry_date' => ['nullable', 'date'], 'retain_until' => ['nullable', 'date'], 'tags' => ['nullable', 'array', 'max:20'], 'tags.*' => ['string', 'max:60'], 'confidentiality' => ['sometimes', Rule::in(['normal', 'internal', 'confidential', 'restricted'])], 'entity_type' => ['nullable', Rule::in(array_keys(DocumentEntityRegistry::TYPES))], 'entity_id' => ['required_with:entity_type', 'integer', 'min:1'], 'change_note' => ['nullable', 'string', 'max:2000'], 'allow_duplicate' => ['boolean']];
    }

    public function index(Request $r)
    {
        $f = $r->validate(['q' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'string'], 'document_type_id' => ['nullable', 'integer'], 'entity_type' => ['nullable', 'string'], 'entity_id' => ['required_with:entity_type', 'integer'], 'created_by' => ['nullable', 'integer'], 'confidentiality' => ['nullable', 'string'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'expiry' => ['nullable', 'string'], 'sort' => ['nullable', 'string']]);

        return response()->json($this->service->listing($f));
    }

    public function config()
    {
        $this->service->permit('documents.view');

        return response()->json(['types' => $this->service->types(), 'settings' => $this->service->settings(), 'extensions' => DocumentService::EXTENSIONS, 'entities' => collect(DocumentEntityRegistry::TYPES)->filter(fn ($c) => $this->service->can($c[2]))->keys()]);
    }

    public function configure(Request $r)
    {
        $this->service->permit('documents.manage');
        $d = $r->validate(['max_file_mb' => ['sometimes', 'integer', 'min:1', 'max:50'], 'expiry_notice_days' => ['sometimes', 'integer', 'min:1', 'max:365'], 'allowed_extensions' => ['sometimes', 'array', 'min:1'], 'allowed_extensions.*' => [Rule::in(DocumentService::EXTENSIONS)], 'type_name' => ['nullable', 'string', 'max:100'], 'requires_review' => ['boolean'], 'entity_type' => ['nullable', Rule::in(array_keys(DocumentEntityRegistry::TYPES))], 'document_type_id' => ['nullable', 'integer'], 'remove_requirement' => ['boolean']]);
        $settings = $this->service->settings();
        $settings->fill(array_intersect_key($d, array_flip(['max_file_mb', 'expiry_notice_days', 'allowed_extensions'])));
        $settings->save();
        if (! empty($d['type_name'])) {
            DocumentType::updateOrCreate(['company_id' => auth()->user()->company_id, 'name' => $d['type_name']], ['requires_review' => $d['requires_review'] ?? false]);
        }if (! empty($d['entity_type']) && ! empty($d['document_type_id'])) {
            DocumentType::findOrFail($d['document_type_id']);
            $key = ['company_id' => auth()->user()->company_id, 'entity_type' => $d['entity_type'], 'document_type_id' => $d['document_type_id']];
            if ($d['remove_requirement'] ?? false) {
                DocumentRequirement::where($key)->delete();
            } else {
                DocumentRequirement::updateOrCreate($key, ['requires_approval' => $d['requires_review'] ?? false]);
            }
        }

return $this->config();
    }

    public function store(Request $r)
    {
        $d = $r->validate([...$this->fields(), 'title' => ['required', 'string', 'max:255'], 'document_type_id' => ['required', 'integer'], 'file' => ['required', 'file']]);
        $result = $this->service->upload($r->file('file'), $d);

        return response()->json($result, isset($result['duplicate']) ? 409 : 201);
    }

    public function show(int $document)
    {
        $record = $this->service->document($document);
        if ($record->confidentiality === 'restricted') $this->service->event($record, 'viewed');
        return response()->json($this->service->detail($record));
    }

    public function update(Request $r, int $document)
    {
        return response()->json($this->service->update($this->service->document($document), $r->validate($this->fields())));
    }

    public function version(Request $r, int $document)
    {
        $d = $r->validate([...$this->fields(), 'file' => ['required', 'file'], 'change_note' => ['required', 'string', 'max:2000']]);
        $result = $this->service->upload($r->file('file'), $d, $this->service->document($document));

        return response()->json($result, isset($result['duplicate']) ? 409 : 201);
    }

    public function action(Request $r, int $document, string $action)
    {
        $d = $r->validate(['entity_type' => [$action === 'link' ? 'required' : 'nullable', Rule::in(array_keys(DocumentEntityRegistry::TYPES))], 'entity_id' => [$action === 'link' ? 'required' : 'nullable', 'integer', 'min:1'], 'link_id' => [$action === 'unlink' ? 'required' : 'nullable', 'integer'], 'legal_hold' => [$action === 'hold' ? 'required' : 'nullable', 'boolean'], 'hold_reason' => [$action === 'hold' ? 'required' : 'nullable', 'string', 'max:2000'], 'decision' => [$action === 'decide' ? 'required' : 'nullable', Rule::in(['approved', 'rejected'])], 'comment' => ['nullable', 'string', 'max:2000']]);

        return response()->json($this->service->action($this->service->document($document), $action, $d));
    }

    public function download(Request $r, int $document, int $version)
    {
        return $this->service->download($this->service->document($document), $version, $r->boolean('preview'));
    }

    public function entities(Request $r, string $type)
    {
        $this->service->permit('documents.view');

        return response()->json($this->entities->options($type, (string) $r->query('q', '')));
    }

    public function requirements(string $type, int $id)
    {
        $this->service->permit('documents.view');

        return response()->json($this->service->requirements($type, $id));
    }

    public function legacy(Request $r)
    {
        $d = $r->validate(['source' => ['required', Rule::in(['quality', 'shipment', 'expense'])], 'source_id' => ['required', 'integer', 'min:1']]);

        return response()->json(app(LegacyDocumentAdapter::class)->import($d['source'], $d['source_id']));
    }

    public function legacyIndex()
    {
        return response()->json(app(LegacyDocumentAdapter::class)->listing());
    }
}
