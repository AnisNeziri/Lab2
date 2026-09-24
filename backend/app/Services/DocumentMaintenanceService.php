<?php

namespace App\Services;

use App\Contracts\DocumentStorageProvider;
use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\DocumentSetting;
use App\Models\DocumentVersion;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DocumentMaintenanceService
{
    public function expiryAlerts(): int
    {
        $count = 0;
        Document::withoutGlobalScopes()->whereNotNull('expiry_date')->where('status', '!=', 'archived')->chunkById(100, function ($docs) use (&$count) {
            foreach ($docs as $d) {
                $days = DocumentSetting::withoutGlobalScopes()->where('company_id', $d->company_id)->value('expiry_notice_days') ?? 30;
                if ($d->expiry_date->gt(today()->addDays($days))) {
                    continue;
                }
                DB::transaction(function () use ($d, &$count) {
                    $event = app(BusinessEventService::class)->record('document.expiring', $d, $d->reference ?? $d->uuid, ['expiry_date' => $d->expiry_date->toDateString()], 'document:expiry:'.$d->id.':'.$d->current_version.':'.$d->expiry_date->toDateString());
                    if (! $event?->wasRecentlyCreated) {
                        return;
                    }
                    foreach (User::withoutGlobalScopes()->where('company_id', $d->company_id)->get() as $user) {
                        $p = app(PermissionService::class);
                        if (! $p->roleHasPermission($user->role, 'documents.view')) {
                            continue;
                        }if ($d->confidentiality === 'restricted' && ! $p->roleHasPermission($user->role, 'documents.manage')) {
                            continue;
                        }if (in_array($d->confidentiality, ['confidential','restricted']) && ! $p->roleHasPermission($user->role, 'documents.confidential')) {
                            continue;
                        }Notification::create(['company_id' => $d->company_id, 'user_id' => $user->id, 'type' => 'document_expiry', 'title' => 'Document expiry / Skadimi i dokumentit', 'message' => $d->title.' · '.$d->expiry_date->toDateString(), 'data' => ['document_id' => $d->id]]);
                        $count++;
                    }
                });
            }
        });

        return $count;
    }

    public function verifyCompany(int $company): array
    {
        $issues = [];
        $storage = app(DocumentStorageProvider::class);
        DocumentVersion::withoutGlobalScopes()->where('company_id', $company)->chunkById(100, function ($versions) use ($storage, &$issues) {
            foreach ($versions as $v) {
                try {
                    $checksum = $v->provider === 'local' ? $storage->checksum($v->storage_key) : null;
                    $status = $checksum ? (hash_equals($v->checksum, $checksum) ? 'valid' : 'mismatch') : 'missing';
                } catch (\Throwable) {
                    $status = 'unavailable';
                }$v->update(['verified_at' => now(), 'integrity_status' => $status]);
                if ($status !== 'valid') {
                    $issues[] = ['document_id' => $v->document_id, 'version' => $v->version, 'status' => $status];
                }
            }
        });
        Document::withoutGlobalScopes()->where('company_id', $company)->chunkById(100, function ($docs) use (&$issues) {
            foreach ($docs as $d) {
                if (! DocumentVersion::withoutGlobalScopes()->where('company_id', $d->company_id)->where('document_id', $d->id)->where('version', $d->current_version)->exists()) {
                    $issues[] = ['document_id' => $d->id, 'status' => 'current_version_missing'];
                }
            }
        });
        DocumentLink::withoutGlobalScopes()->where('company_id', $company)->chunkById(100, function ($links) use (&$issues, $company) {
            foreach ($links as $l) {
                $table = DocumentEntityRegistry::TYPES[$l->entity_type][1] ?? null;
                if (! $table || ! DB::table($table)->where('company_id', $company)->where('id', $l->entity_id)->exists()) {
                    $issues[] = ['document_id' => $l->document_id, 'link_id' => $l->id, 'status' => 'broken_link'];
                }
            }
        });

        return $issues;
    }
}
