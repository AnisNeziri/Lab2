<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\Onboarding\OnboardingBackupService;
use App\Services\Onboarding\OnboardingPosting;
use App\Services\Onboarding\OnboardingSchema;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\OnboardingValidation;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(private OnboardingService $service) {}

    public function index()
    {
        return response()->json($this->service->listing());
    }

    public function store(Request $r)
    {
        $s = $this->service->create($r->all());

        return response()->json($this->service->detail($s->id), 201);
    }

    public function show(int $id)
    {
        return response()->json($this->service->detail($id));
    }

    public function update(Request $r, int $id)
    {
        $this->service->update($id, $r->all());

        return $this->show($id);
    }

    public function rows(Request $r, int $id)
    {
        return response()->json($this->service->rows($id, $r->all()));
    }

    public function upload(Request $r, int $id)
    {
        $r->validate(['file' => 'required|file|max:10240']);
        $this->service->upload($id, $r->file('file'), $r->all());

        return $this->show($id);
    }

    public function mapping(Request $r, int $id, int $batch)
    {
        $this->service->map($id, $batch, $r->all());

        return $this->show($id);
    }

    public function resumeSource(Request $r, int $id, int $batch)
    {
        $v = $r->validate(['revision' => 'required|integer']);
        $this->service->resumeSource($id, $batch, $v['revision']);

        return $this->show($id);
    }

    public function excludeSource(Request $r, int $id, int $batch)
    {
        $v = $r->validate(['revision' => 'required|integer', 'reason' => 'required|string|min:5|max:500']);
        $this->service->excludeSource($id, $batch, $v['revision'], $v['reason']);

        return $this->show($id);
    }

    public function editRows(Request $r, int $id)
    {
        $this->service->editRows($id, $r->all());

        return $this->show($id);
    }

    public function validateSource(Request $r, int $id)
    {
        $v = $r->validate(['revision' => 'required|integer']);

        return response()->json(app(OnboardingValidation::class)->validate($id, $v['revision']));
    }

    public function review(Request $r, int $id)
    {
        app(OnboardingPosting::class)->review($id, $r->all());

        return $this->show($id);
    }

    public function approve(Request $r, int $id)
    {
        app(OnboardingPosting::class)->approve($id, $r->all());

        return $this->show($id);
    }

    public function post(Request $r, int $id)
    {
        return response()->json(app(OnboardingPosting::class)->post($id, $r->all()));
    }

    public function verify(int $id)
    {
        return response()->json(app(OnboardingPosting::class)->verify($id));
    }

    public function lock(Request $r, int $id)
    {
        app(OnboardingPosting::class)->lock($id, $r->all());

        return $this->show($id);
    }

    public function cancel(Request $r, int $id)
    {
        $v = $r->validate(['revision' => 'required|integer', 'reason' => 'required|string|min:5|max:500']);
        $this->service->cancel($id, $v['revision'], $v['reason']);

        return $this->show($id);
    }

    public function backup(Request $r, int $id)
    {
        app(OnboardingBackupService::class)->create($id, $r->all());

        return $this->show($id);
    }

    public function backupDownload(int $id, int $backup)
    {
        return app(OnboardingBackupService::class)->download($id, $backup);
    }

    public function restoreVerify(Request $r, int $id)
    {
        return response()->json(app(OnboardingBackupService::class)->restoreVerify($id, $r->all()));
    }

    public function template(string $domain)
    {
        $this->service->permit('manage');
        abort_unless(isset(OnboardingSchema::DOMAINS[$domain]), 404);

        return response(OnboardingSchema::template($domain), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="AIMS-opening-'.$domain.'.csv"', 'Cache-Control' => 'no-store, private']);
    }

    public function source(int $id, int $batch)
    {
        $this->service->permit('manage');
        $s = $this->service->get($id);
        $b = $s->batches()->findOrFail($batch);
        $this->service->event($s, 'source.downloaded', ['batch_id' => $b->id]);

        return response(base64_decode($b->source_base64, true), 200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="source-'.$b->id.'.'.$b->format.'"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function evidence(int $id)
    {
        $d = $this->service->detail($id);
        $s = $d['session'];
        $posting = $s->events()->where('action', 'posting.started')->orderBy('id')->first()?->context ?? [];
        $companyName = $posting['company_name'] ?? Company::findOrFail($s->company_id)->name;
        $text = "AIMS controlled opening evidence\nSession: {$s->public_id}\nOpening: {$s->name}\nCompany: {$companyName} (#{$s->company_id})\nAIMS version at posting: ".($posting['aims_version'] ?? 'NOT POSTED / UNRECORDED')."\nOpening contract: ".($posting['contract'] ?? OnboardingSchema::CONTRACT)."\nSource period: {$s->source_period}\nCutover: {$s->cutover_date->toDateString()} ({$s->timezone})\nCurrency: {$s->currency}\nStatus: {$s->status}\nApproved fingerprint: {$s->approved_fingerprint}\n";
        foreach (['created' => 'Prepared', 'reviewed' => 'Reviewed', 'approved' => 'Approved', 'locked' => 'Locked'] as $field => $label) {
            $actorId = $s->{$field.'_by'};
            $actor = $actorId ? User::withTrashed()->where('company_id', $s->company_id)->find($actorId) : null;
            $text .= $label.' by: '.($actor ? $actor->name.' (#'.$actorId.')' : ($actorId ? '#'.$actorId : 'NOT RECORDED')).'; at '.($s->{$field.'_at'}?->toIso8601String() ?? 'NOT RECORDED')."\n";
        }
        $text .= 'Posting started: '.($s->posting_started_at?->toIso8601String() ?? 'NOT STARTED')."\nPosted: ".($s->posted_at?->toIso8601String() ?? 'NOT POSTED')."\nVerified: ".($s->verified_at?->toIso8601String() ?? 'NOT VERIFIED')."\n";
        foreach ($d['progress'] as $group) {
            $text .= 'Rows: '.$group['domain'].'; '.$group['status'].'; count '.$group['count']."\n";
        }
        foreach ($d['reconciliation']['sections'] as $key => $value) {
            $text .= ucwords(str_replace('_', ' ', $key)).': source '.($value['source'] ?? 'UNKNOWN').'; staged '.$value['staged'].'; AIMS '.($value['actual'] ?? 'not posted').'; difference '.($value['actual_difference'] ?? $value['difference'] ?? 'UNKNOWN')."\n";
        }
        foreach ($d['batches'] as $b) {
            $text .= "Source {$b->id}: {$b->filename}; SHA256 {$b->checksum}; rows {$b->row_count}; warning rows {$b->warning_count}; error rows {$b->error_count}; active ".($b->is_active ? 'yes' : 'no')."\n";
        }
        foreach ($d['reconciliation']['source_inventory_units'] ?? [] as $unit => $quantity) {
            $text .= 'Inventory quantity '.$unit.': source '.$quantity.'; staged '.($d['reconciliation']['inventory_units'][$unit] ?? '0').'; AIMS '.($d['reconciliation']['actual_inventory_units'][$unit] ?? 'NOT POSTED')."\n";
        }
        $warnings = [];
        foreach ($s->rows()->active()->whereNotNull('warnings')->cursor() as $row) {
            foreach ($row->warnings ?? [] as $code) {
                $warnings[$code] = ($warnings[$code] ?? 0) + 1;
            }
        }
        foreach ($warnings as $code => $count) {
            $text .= 'Warning: '.$code.'; rows '.$count."\n";
        }
        $text .= 'Integrity: '.(($s->verification['passed'] ?? false) ? 'PASS' : 'NOT VERIFIED').'; products checked '.($s->verification['inventory']['products_checked'] ?? 0).'; accounting reconciled '.(($s->verification['accounting']['all_reconciled'] ?? false) ? 'YES' : 'NOT VERIFIED')."\n";
        foreach ($s->backups()->whereIn('id', array_filter([$s->pre_backup_id, $s->post_backup_id]))->get() as $backup) {
            $text .= 'Backup '.$backup->kind.': #'.$backup->id.'; SHA256 '.$backup->checksum.'; authenticated '.(($backup->verification['authenticated'] ?? false) ? 'YES' : 'NO')."\n";
        }
        $text .= 'Independent destination reference: '.($s->restore_verification['destination_ref'] ?? 'NONE').'; backup SHA256 '.($s->restore_verification['backup_checksum'] ?? 'NONE')."\n";
        $text .= 'Independent restore: '.(($s->restore_verification['passed'] ?? false) ? 'PASS' : 'NOT VERIFIED')."\nLocked: ".($s->locked_at?->toIso8601String() ?? 'NO')."\n";

        return response($text, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="AIMS-opening-'.$s->public_id.'-evidence.txt"', 'Cache-Control' => 'no-store, private']);
    }
}
