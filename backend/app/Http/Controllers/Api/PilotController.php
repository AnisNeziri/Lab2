<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PilotEvidence;
use App\Models\PilotFeedback;
use App\Models\PilotProfile;
use App\Models\PilotReport;
use App\Models\User;
use App\Services\DocumentEntityRegistry;
use App\Services\PermissionService;
use App\Services\PilotImportPreflight;
use App\Services\PilotReadinessService;
use App\Services\PilotService;
use App\Services\PilotTelemetryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PilotController extends Controller
{
    private function json($data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }

    public function index(PilotService $pilot, PilotReadinessService $readiness)
    {
        $pilot->owner();

        return $this->json(['readiness' => $readiness->report(), 'users' => User::where('company_id', auth()->user()->company_id)->where('is_active', true)->orderBy('id')->get(['id', 'name', 'role']),
            'options' => ['workstreams' => PilotService::STREAMS, 'categories' => PilotService::CATEGORIES, 'evidence_kinds' => PilotService::KINDS],
            'operator_selected' => (int) config('pilot.company_id') === (int) auth()->user()->company_id]);
    }

    public function save(Request $request, PilotService $pilot)
    {
        $pilot->owner();
        $data = $request->validate([
            'revision' => 'required|integer|min:0', 'status' => ['required', Rule::in(['draft', 'active', 'paused'])],
            'cutover_date' => 'nullable|date_format:Y-m-d', 'timezone' => 'required|timezone',
            'business_days' => 'required|array|min:1|max:7', 'business_days.*' => 'integer|between:1,7|distinct',
            'workstreams' => 'required|array|min:1|max:6', 'workstreams.*' => [Rule::in(PilotService::STREAMS), 'distinct'],
            'participants' => 'required|array|min:1|max:50', 'participants.*.user_id' => 'required|integer|min:1',
            'participants.*.responsibility' => ['required', Rule::in(['owner', 'sales', 'warehouse', 'purchasing', 'finance', 'manager'])],
            'participants.*.tasks' => 'required|string|max:500', 'telemetry_enabled' => 'required|boolean',
            'attest_real_data' => 'sometimes|boolean', 'source_document_ids' => 'sometimes|array|max:20', 'source_document_ids.*' => 'integer|min:1|distinct',
        ]);
        // Do not store arbitrary extra participant fields supplied by a client.
        $data['participants'] = array_map(fn ($p) => array_intersect_key($p, array_flip(['user_id', 'responsibility', 'tasks'])), $data['participants']);

        return $this->json($pilot->save($data));
    }

    private function contextRules(): array
    {
        return ['entity_type' => ['nullable', 'required_with:entity_id', Rule::in(array_merge(array_keys(DocumentEntityRegistry::TYPES), ['inventory-count']))], 'entity_id' => 'nullable|required_with:entity_type|integer|min:1'];
    }

    public function feedback(Request $request, PilotService $pilot)
    {
        $data = $request->validate($this->contextRules() + ['category' => ['required', Rule::in(PilotService::CATEGORIES)], 'page' => 'required|string|max:500', 'description' => 'nullable|string|max:2000', 'reference' => 'nullable|regex:/^AIMS-[a-f0-9]{16}$/']);

        return $this->json(['id' => $pilot->feedback($data)->id, 'status' => 'open'], 201);
    }

    public function feedbackList(Request $request, PilotService $pilot)
    {
        $pilot->owner();
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'in_progress', 'resolved'])], 'page' => 'integer|min:1|max:10000']);

        return $this->json(PilotFeedback::when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->latest('id')->paginate(25));
    }

    public function triage(int $id, Request $request, PilotService $pilot)
    {
        $pilot->owner();

        return $this->json($pilot->triage($id, $request->validate(['priority' => ['required', Rule::in(['P0', 'P1', 'P2', 'P3', 'untriaged'])], 'status' => ['required', Rule::in(['open', 'in_progress', 'resolved'])], 'resolution' => 'nullable|required_if:status,resolved|string|max:2000'])));
    }

    public function evidence(Request $request, PilotService $pilot)
    {
        return $this->json($pilot->evidence($request->validate($this->contextRules() + [
            'kind' => ['required', Rule::in(PilotService::KINDS)], 'observed_on' => 'required|date_format:Y-m-d|before_or_equal:'.now(PilotProfile::value('timezone') ?: config('app.timezone'))->toDateString(),
            'outcome' => ['required', Rule::in(['confirmed', 'failed', 'not_tested', 'not_applicable'])],
            'document_id' => 'nullable|integer|min:1', 'checks' => 'required|array|min:1|max:30',
            'checks.*.name' => 'required|string|max:100', 'checks.*.result' => ['required', Rule::in(['confirmed', 'failed', 'not_tested', 'not_applicable'])],
            'notes' => 'nullable|string|max:2000',
        ])), 201);
    }

    public function evidenceList(Request $request, PilotService $pilot)
    {
        $request->validate(['page' => 'integer|min:1|max:10000']);
        $q = PilotEvidence::query();
        if (auth()->user()->role !== 'admin') {
            $q->where(function ($q) {
                $q->where('prepared_by', auth()->id());
                if (app(PermissionService::class)->roleHasPermission(auth()->user()->role, 'accounting.reports.view')) {
                    $q->orWhereIn('kind', PilotService::FINANCE);
                }
            });
        }

        return $this->json($q->latest('id')->paginate(25));
    }

    public function context(PilotService $pilot, PilotTelemetryService $telemetry)
    {
        return $this->json(['telemetry_enabled' => $telemetry->enabled(), 'evidence_kinds' => $pilot->allowedKinds()]);
    }

    public function review(int $id, PilotService $pilot)
    {
        return $this->json($pilot->review($id));
    }

    public function visit(PilotTelemetryService $telemetry)
    {
        $telemetry->visit();

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function snapshot(Request $request, PilotReadinessService $readiness, PilotService $pilot)
    {
        $pilot->owner();
        $data = $request->validate(['kind' => ['required', Rule::in(['daily', 'weekly', 'day30', 'day60', 'day90', 'baseline', 'exit'])]]);

        return $this->json($readiness->snapshot($data['kind']), 201);
    }

    public function reports(Request $request, PilotService $pilot)
    {
        $pilot->owner();
        $request->validate(['page' => 'integer|min:1|max:10000']);

        return $this->json(PilotReport::latest('id')->paginate(10, ['id', 'kind', 'period_start', 'period_end', 'captured_at', 'captured_by', 'is_historical']));
    }

    public function report(int $id, PilotService $pilot)
    {
        $pilot->owner();

        return $this->json(PilotReport::findOrFail($id));
    }

    public function incidents(Request $request, PilotService $pilot)
    {
        $pilot->owner();
        $data = $request->validate(['reference' => 'nullable|regex:/^AIMS-[a-f0-9]{16}$/']);

        return $this->json(DB::table('pilot_incidents')->where('company_id', auth()->user()->company_id)->when($data['reference'] ?? null, fn ($q, $ref) => $q->where('reference', $ref))->latest('id')->limit(50)->get(['reference', 'route_key', 'exception_type', 'http_status', 'occurred_at']));
    }

    public function preflight(Request $request, PilotImportPreflight $imports, PilotService $pilot)
    {
        $pilot->owner();
        $data = $request->validate(['dataset' => ['required', Rule::in(array_keys(PilotImportPreflight::FIELDS))], 'file' => 'required|file|max:5120|extensions:csv,txt']);

        return $this->json($imports->inspect($data['dataset'],$request->file('file')));
    }
}
