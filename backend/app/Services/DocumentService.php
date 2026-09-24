<?php

namespace App\Services;

use App\Contracts\DocumentStorageProvider;
use App\Models\ActivityLog;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\BusinessEvent;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\DocumentRequirement;
use App\Models\DocumentSetting;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DocumentService
{
    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'txt', 'csv', 'docx', 'xlsx'];

    public const INITIAL_TYPES = ['Purchase Order', 'Supplier Invoice', 'Customer Invoice', 'Packing List', 'Bill of Lading', 'Customs Declaration', 'Certificate', 'Quality Evidence', 'Supplier Claim Evidence', 'Proof of Delivery', 'Contract', 'Agreement', 'Payment Evidence', 'Bank Document', 'Product Specification', 'Safety/Compliance Document', 'Return Evidence', 'Internal Document', 'Other'];

    public function __construct(private DocumentStorageProvider $storage, private DocumentEntityRegistry $entities, private PermissionService $permissions) {}

    public function can(string $permission): bool
    {
        return Auth::user()?->company_id && $this->permissions->roleHasPermission(Auth::user()->role, $permission);
    }

    public function permit(string $permission): void
    {
        abort_unless($this->can($permission), 403);
    }

    public function visibleQuery()
    {
        $this->permit('documents.view');
        $q = Document::query()->where('company_id', Auth::user()->company_id);
        if (! $this->can('documents.confidential')) {
            $q->whereNotIn('confidentiality', ['confidential', 'restricted']);
        } elseif (! $this->can('documents.manage')) {
            $q->where('confidentiality', '!=', 'restricted');
        }

        return $q;
    }

    public function document(int $id): Document
    {
        return $this->visibleQuery()->findOrFail($id);
    }

    public function settings(): DocumentSetting
    {
        return DocumentSetting::firstOrNew(['company_id' => Auth::user()->company_id]);
    }

    public function types()
    {
        foreach (self::INITIAL_TYPES as $name) {
            DocumentType::firstOrCreate(['company_id' => Auth::user()->company_id, 'name' => $name]);
        }

return DocumentType::orderBy('name')->get();
    }

    public function listing(array $f)
    {
        $q = $this->visibleQuery()->with(['type', 'currentFile']);
        if (! empty($f['q'])) {
            $s = '%'.addcslashes($f['q'], '%_').'%';
            $q->where(function($q) use($s) {
                $q->where('title','like',$s)->orWhere('reference','like',$s)->orWhere('issuer','like',$s)->orWhere('tags','like',$s)->orWhereHas('type',fn($type)=>$type->where('name','like',$s));
                foreach(DocumentEntityRegistry::TYPES as $type=>[$model,$table,$permission,$field]) {
                    if(!$this->can($permission)||!\Illuminate\Support\Facades\Schema::hasColumn($table,$field))continue;
                    $q->orWhereHas('links',fn($links)=>$links->where('entity_type',$type)->whereExists(fn($entity)=>$entity->selectRaw('1')->from($table)->whereColumn($table.'.id','document_links.entity_id')->where($table.'.company_id',Auth::user()->company_id)->where($table.'.'.$field,'like',$s)));
                }
            });
        }
        foreach (['status', 'document_type_id', 'confidentiality', 'created_by'] as $field) {
            if (! empty($f[$field])) {
                $q->where($field, $f[$field]);
            }
        }
        if (! empty($f['entity_type']) && ! empty($f['entity_id'])) {
            $this->entities->resolve($f['entity_type'], (int) $f['entity_id']);
            $q->whereHas('links', fn ($l) => $l->where('entity_type', $f['entity_type'])->where('entity_id', $f['entity_id']));
        }
        if (! empty($f['from'])) {
            $q->whereDate('created_at', '>=', $f['from']);
        } if (! empty($f['to'])) {
            $q->whereDate('created_at', '<=', $f['to']);
        }
        if (($f['expiry'] ?? '') === 'expired') {
            $q->where('expiry_date', '<', today());
        }
        if (($f['expiry'] ?? '') === 'soon') {
            $q->whereBetween('expiry_date', [today(), today()->addDays($this->settings()->expiry_notice_days)]);
        }
        if (($f['expiry'] ?? '') === 'retention') {
            $q->whereNotNull('retain_until')->where('retain_until', '<=', today())->where('legal_hold', false);
        }
        $sort = in_array($f['sort'] ?? '', ['title', 'expiry_date', 'created_at']) ? $f['sort'] : 'created_at';

        return $q->orderBy($sort, $sort === 'created_at' ? 'desc' : 'asc')->orderByDesc('id')->paginate(25);
    }

    public function detail(Document $d): array
    {
        $d = $this->document($d->id);
        $d->load('type', 'versions.approval.decisions', 'links');
        $links = [];
        foreach ($d->links as $link) {
            try {
                $links[] = ['link_id' => $link->id, ...$this->entities->describe($link->entity_type, $link->entity_id)];
            } catch (HttpExceptionInterface|ModelNotFoundException $e) { /* Do not expose an entity the actor cannot view. */
            }
        }

        return [...$d->toArray(), 'links' => $links, 'expiry_state' => $d->expiry_date ? ($d->expiry_date->lt(today()) ? 'expired' : ($d->expiry_date->lte(today()->addDays($this->settings()->expiry_notice_days)) ? 'expiring_soon' : 'valid')) : 'none', 'retention_review_due' => ! $d->legal_hold && $d->retain_until?->lte(today()), 'activity' => BusinessEvent::query()->where('entity_type', 'Document')->where('entity_id', $d->id)->latest('id')->limit(50)->get()];
    }

    private function validateFile(UploadedFile $file): array
    {
        $settings = $this->settings();
        $name = $file->getClientOriginalName();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = array_intersect(self::EXTENSIONS, $settings->allowed_extensions ?? self::EXTENSIONS);
        $bad = preg_match('~[\\\\/\x00-\x1f]|\.(php\d*|phtml|exe|com|bat|cmd|js|html?|svg|ps1|scr|vbs)(\.|$)~i', $name);
        if (! $file->isValid() || $bad || ! in_array($extension, $allowed) || $file->getSize() > min(50, $settings->max_file_mb) * 1048576 || $file->getSize() === 0) {
            throw ValidationException::withMessages(['file' => 'Choose an allowed, non-empty file within the configured size limit, with a safe filename.']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $mimes = ['pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv'], 'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']];
        if (! in_array($mime, $mimes[$extension])) {
            throw ValidationException::withMessages(['file' => 'The file contents do not match the filename type.']);
        }
        if (in_array($extension, ['docx', 'xlsx'])) {
            $zip = new \ZipArchive;
            if ($zip->open($file->getRealPath()) !== true) {
                throw ValidationException::withMessages(['file' => 'Invalid Office file.']);
            } try {
                if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml') === false || $zip->numFiles > 10000) {
                    throw ValidationException::withMessages(['file' => 'Invalid Office document.']);
                } for ($i = 0; $i < $zip->numFiles; $i++) {
                    if (preg_match('~vbaProject|\.exe$|\.js$~i', $zip->getNameIndex($i))) {
                        throw ValidationException::withMessages(['file' => 'Active executable content is not allowed.']);
                    }
                }
            } finally {
                $zip->close();
            }
        }

        return ['filename' => mb_substr($name, 0, 240), 'mime_type' => $mime, 'size' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath())];
    }

    public function upload(UploadedFile $file, array $data, ?Document $document = null): array
    {
        $this->permit($document ? 'documents.new_version' : 'documents.upload');
        $meta = $this->validateFile($file);

        return DB::transaction(function () use ($file, $data, $document, $meta) {
            Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();
            if ($document) {
                $document = $this->document($document->id);
                $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
                abort_if($document->status === 'archived', 422, 'Restore the document before adding a version.');
            }
            $duplicates = $this->visibleQuery()->whereHas('versions', fn ($q) => $q->where('checksum', $meta['checksum']))->get(['id', 'title', 'uuid']);
            if ($duplicates->isNotEmpty() && ! ($data['allow_duplicate'] ?? false)) {
                return ['duplicate' => true, 'documents' => $duplicates];
            }
            if (! $document) {
                $type = DocumentType::findOrFail($data['document_type_id']);
                $this->checkClassification($data['confidentiality'] ?? 'internal');
                $document = Document::create([...$this->metadata($data), 'document_type_id' => $type->id, 'uuid' => (string) Str::uuid(), 'status' => $type->requires_review ? 'draft' : 'active', 'created_by' => Auth::id()]);
            } else {
                app(ApprovalService::class)->invalidate('document_version', (int) ($document->versions()->where('version', $document->current_version)->value('id')), 'document_review', 'A new document version was uploaded.');
                $document->current_version++;
                $document->status = $document->type->requires_review ? 'draft' : 'active';
                $document->save();
            }
            // Equal bytes may share storage, but the document identities never merge automatically.
            $existing = DocumentVersion::where('checksum', $meta['checksum'])->first();
            $key = $existing?->storage_key;
            if (! $key || ! $this->storage->exists($key)) {
                $key = Auth::user()->company_id.'/'.substr($meta['checksum'], 0, 2).'/'.Str::uuid();
                $stream = fopen($file->getRealPath(), 'rb');
                try {
                    $this->storage->put($key, $stream);
                } finally {
                    fclose($stream);
                }
            }
            $version = DocumentVersion::create([...$meta, 'document_id' => $document->id, 'version' => $document->current_version, 'storage_key' => $key, 'provider' => 'local', 'uploaded_by' => Auth::id(), 'change_note' => $data['change_note'] ?? null]);
            $this->event($document, $version->version === 1 ? 'created' : 'version_created', ['version' => $version->version]);
            if (! empty($data['entity_type']) && ! empty($data['entity_id'])) {
                $this->link($document, $data['entity_type'], (int) $data['entity_id']);
            }

            return $this->detail($document);
        });
    }

    private function metadata(array $data): array
    {
        return array_intersect_key($data, array_flip(['title', 'description', 'document_type_id', 'reference', 'issuer', 'document_date', 'expiry_date', 'retain_until', 'tags', 'confidentiality']));
    }

    private function checkClassification(string $value): void
    {
        if ($value === 'restricted') {
            $this->permit('documents.manage');
        } elseif ($value === 'confidential') {
            $this->permit('documents.confidential');
        }
    }

    public function update(Document $d, array $data): array
    {
        $this->permit('documents.update_metadata');

        return DB::transaction(function () use ($d, $data) {
            $d = $this->document($d->id);
            if (isset($data['confidentiality'])) {
                $this->checkClassification($data['confidentiality']);
            } if (isset($data['document_type_id'])) {
                DocumentType::findOrFail($data['document_type_id']);
            } $old = $d->toArray();
            $d->update($this->metadata($data));
            ActivityLog::create(['company_id' => $d->company_id, 'user_id' => Auth::id(), 'action' => 'document.metadata_changed', 'entity' => 'Document', 'entity_id' => $d->id, 'description' => 'Document metadata changed.', 'old_value' => $old, 'new_value' => $d->toArray()]);
            $this->event($d, 'metadata_changed');

            return $this->detail($d);
        });
    }

    public function link(Document $d, string $type, int $id): void
    {
        $this->permit('documents.update_metadata');
        $this->entities->resolve($type, $id);
        $link = DocumentLink::firstOrCreate(['document_id' => $d->id, 'entity_type' => $type, 'entity_id' => $id], ['linked_by' => Auth::id()]);
        if ($link->wasRecentlyCreated) {
            $this->event($d, 'linked', ['entity_type' => $type, 'entity_id' => $id]);
        }
    }

    public function action(Document $document, string $action, array $data): array
    {
        return DB::transaction(function () use ($document, $action, $data) {
            $d = $this->document($document->id);
            $d = Document::whereKey($d->id)->lockForUpdate()->firstOrFail();
            switch ($action) {
                case 'link':$this->link($d, $data['entity_type'], (int) $data['entity_id']);
                    break;
                case 'unlink':$this->permit('documents.update_metadata');
                    abort_if($d->legal_hold, 422, 'Legal hold prevents removing relationships.');
                    $l = $d->links()->findOrFail($data['link_id']);
                    $this->entities->resolve($l->entity_type, $l->entity_id);
                    $this->event($d, 'unlinked', $l->only(['entity_type', 'entity_id']));
                    $l->delete();
                    break;
                case 'archive':case 'restore':$this->permit('documents.archive');
                    $d->update(['status' => $action === 'archive' ? 'archived' : ($d->type->requires_review ? 'draft' : 'active'), 'archived_at' => $action === 'archive' ? now() : null]);
                    $this->event($d, $action === 'archive' ? 'archived' : 'restored');
                    break;
                case 'hold':$this->permit('documents.manage');
                    $d->update(['legal_hold' => (bool) $data['legal_hold'], 'hold_reason' => $data['hold_reason']]);
                    $this->event($d, 'legal_hold_changed', ['legal_hold' => $d->legal_hold, 'reason' => $d->hold_reason]);
                    break;
                case 'verify':$this->permit('documents.manage');
                    foreach ($d->versions as $v) {
                        $actual = $this->storage->checksum($v->storage_key);
                        $v->update(['verified_at' => now(), 'integrity_status' => $actual && hash_equals($v->checksum, $actual) ? 'valid' : ($actual ? 'mismatch' : 'missing')]);
                    }$this->event($d, 'integrity_verified');
                    break;
                case 'review':
                    $this->permit('documents.review');
                    abort_if($d->status === 'archived', 422, 'Archived documents cannot be reviewed.');
                    $v = $d->versions()->where('version', $d->current_version)->firstOrFail();
                    $a = $v->approval;
                    if (! $a || in_array($a->status, ['cancelled', 'rejected'])) {
                        $rule = ApprovalRule::where('rule_type', 'document_review')->first();
                        abort_if($rule && ! $rule->is_active, 422, 'Document reviews are disabled.');
                        $a = ApprovalRequest::create(['company_id' => $d->company_id, 'entity_type' => 'document_version', 'entity_id' => $v->id, 'rule_type' => 'document_review', 'requested_by' => Auth::id(), 'requested_at' => now(), 'required_user_id' => $rule?->required_user_id, 'required_role' => $rule?->required_role, 'status' => 'pending', 'requested_amount' => '0.00', 'currency' => 'EUR', 'context' => ['document_id' => $d->id, 'title' => $d->title, 'version' => $v->version, 'checksum' => $v->checksum]]);
                        $v->update(['approval_request_id' => $a->id]);
                        $this->event($d, 'review_requested', ['version' => $v->version]);
                    }
                    $d->update(['status' => $a->status === 'approved' ? 'approved' : 'under_review']);
                    break;
                case 'decide':$this->permit('documents.review');
                    $this->permit('approvals.decide');
                    $v = $d->versions()->where('version', $d->current_version)->firstOrFail();
                    abort_unless($v->approval_request_id, 422);
                    app(ApprovalService::class)->decide($v->approval, $data['decision'], $data['comment'] ?? null);
                    break;
                default:abort(404);
            }

            return $this->detail($d->fresh());
        });
    }

    public function download(Document $document, int $number, bool $preview = false)
    {
        $this->permit('documents.download');
        $d = $this->document($document->id);
        $v = $d->versions()->where('version', $number)->firstOrFail();
        abort_unless(str_starts_with($v->storage_key, $d->company_id.'/'), 409, 'Invalid document ownership.');
        abort_unless($v->provider === 'local' && $this->storage->exists($v->storage_key), 409, 'The document file is missing. Restore a verified backup.');
        abort_unless(hash_equals($v->checksum, (string) $this->storage->checksum($v->storage_key)), 409, 'Document integrity check failed. The original evidence has not been changed.');
        $inline = $preview && in_array($v->mime_type, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
        $this->event($d, $inline ? 'previewed' : 'downloaded', ['version' => $number]);
        $stream = $this->storage->read($v->storage_key);

        return response()->stream(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, ['Content-Type' => $v->mime_type, 'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', $v->filename).'"', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'Cache-Control' => 'private, no-store']);
    }

    public function requirements(string $type, int $id): array
    {
        $this->entities->resolve($type, $id);
        $rows = [];
        foreach (DocumentRequirement::with('type')->where('entity_type', $type)->get() as $r) {
            $docs = $this->visibleQuery()->where('document_type_id', $r->document_type_id)->where('status', '!=', 'archived')->whereHas('links', fn ($q) => $q->where('entity_type', $type)->where('entity_id', $id))->get();
            $valid = $docs->filter(fn ($d) => ! $d->expiry_date || $d->expiry_date->gte(today()));
            $state = $docs->isEmpty() ? 'missing' : ($valid->isEmpty() ? 'expired' : ($r->requires_approval && ! $valid->contains('status', 'approved') ? 'review_required' : 'received'));
            $rows[] = ['type' => $r->type->name, 'type_labels' => $r->type->labels, 'status' => $state];
        }

return ['status' => collect($rows)->contains('status', 'missing') ? 'incomplete' : (collect($rows)->contains('status', 'expired') ? 'expired' : (collect($rows)->contains('status', 'review_required') ? 'review_required' : 'complete')), 'requirements' => $rows];
    }

    public function event(Document $d, string $action, array $context = []): void
    {
        app(BusinessEventService::class)->record('document.'.$action, $d, $d->reference ?? $d->uuid, $context, 'document:'.$d->id.':'.Str::uuid());
        if (in_array($action, ['review_requested', 'approved', 'rejected'])) {
            foreach (User::query()->where('company_id', $d->company_id)->get() as $u) {
                if ($u->id === Auth::id()) {
                    continue;
                } if ($action === 'review_requested' && ! $this->permissions->roleHasPermission($u->role,'documents.review')) {
                    continue;
                }if ($action !== 'review_requested' && $u->id !== $d->created_by) {
                    continue;
                }if (! $this->permissions->roleHasPermission($u->role,'documents.view')) {
                    continue;
                }if ($d->confidentiality === 'restricted' && ! $this->permissions->roleHasPermission($u->role,'documents.manage')) {
                    continue;
                }if (in_array($d->confidentiality, ['confidential','restricted']) && ! $this->permissions->roleHasPermission($u->role,'documents.confidential')) {
                    continue;
                }Notification::create(['company_id' => $d->company_id, 'user_id' => $u->id, 'type' => 'document_review', 'title' => 'Document review / Shqyrtimi i dokumentit', 'message' => $d->title.' · '.$action, 'data' => ['document_id' => $d->id]]);
            }
        }
    }
}
