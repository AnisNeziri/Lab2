<?php

namespace App\Services\Workforce;

use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\WorkforceRecord;
use App\Services\AimsToolRegistry;
use App\Services\AssistantLanguage;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkforceAssistantFacts
{
    public const TOOLS = ['get_workforce_summary' => 'employees.view', 'get_payroll_summary' => 'payroll.view', 'get_unpaid_payroll' => 'payroll.view', 'get_pending_workforce_approvals' => 'workforce.approve'];

    public function intent(string $question): ?string
    {
        $q = app(AssistantLanguage::class)->normalize($question);
        $q = strtr($q, ['payrol' => 'payroll', 'pension' => 'pension', 'employes' => 'employees']);
        if (! preg_match('/payroll|payrolll|salary|salaries|payslip|employee|overtime|leave request|workforce|\bpaga\b|\bpage\b|pagat|pushim|punonjes|punetor|ore.*shtes/', $q)) {
            return null;
        }
        if (preg_match('/\b(post|reverse|pay|approve|fire|dismiss|rank|change|send|submit|delete|posto|paguaj|mirato|largo|pusho|ndrysho|dorezo|fshi)\b/', $q)) {
            return 'boundary';
        }
        if (preg_match('/overtime|leave|pushim|shtes/', $q)) {
            return 'get_pending_workforce_approvals';
        }
        if (preg_match('/active|how many|sa.*pun|aktive|aktiv/', $q)) {
            return 'get_workforce_summary';
        }
        if (preg_match('/unpaid|outstanding.*(?:salary|payroll)|nuk.*paguar|papaguar/', $q)) {
            return 'get_unpaid_payroll';
        }

        return 'get_payroll_summary';
    }

    public function read(string $tool): array
    {
        app(WorkforceService::class)->permit(self::TOOLS[$tool] ?? 'payroll.view');

        return match ($tool) {
            'get_workforce_summary' => ['active_employees' => Employee::whereIn('status', ['active', 'on_leave'])->count(), 'qualification' => 'Active and on-leave employment records. No salary details.'],
            'get_pending_workforce_approvals' => ['requests' => WorkforceRecord::where('status', 'requested')->whereIn('kind', ['leave', 'overtime'])->selectRaw('kind,COUNT(*) as count')->groupBy('kind')->get()->toArray(), 'items' => WorkforceRecord::with('employee:id,name,employee_number')->where('status', 'requested')->whereIn('kind', ['leave', 'overtime'])->orderBy('starts_on')->limit(10)->get(['id', 'employee_id', 'kind', 'starts_on', 'ends_on', 'units'])->toArray(), 'qualification' => 'Pending leave/overtime counts and at most 10 requests. Human approval is required.'],
            'get_unpaid_payroll' => $this->unpaid(),
            'get_payroll_summary' => app(PayrollReadService::class)->summary(),
        };
    }

    private function unpaid(): array
    {
        $paid = DB::table('payroll_payments')->where('company_id', Auth::user()->company_id)->where('kind', 'salary')->groupBy('payroll_line_id')->selectRaw('payroll_line_id,SUM(amount) paid');
        $rows = PayrollLine::with('run.period')->leftJoinSub($paid, 'settled', fn ($join) => $join->on('settled.payroll_line_id', '=', 'payroll_lines.id'))->whereHas('run', fn ($q) => $q->whereIn('status', ['posted', 'paid']))->whereRaw('payroll_lines.net>COALESCE(settled.paid,0)')->orderBy('payroll_lines.id')->limit(20)->get(['payroll_lines.id', 'payroll_lines.payroll_run_id', 'payroll_lines.snapshot', DB::raw('payroll_lines.net-COALESCE(settled.paid,0) remaining')]);

        return ['items' => $rows->map(fn ($r) => ['name' => $r->snapshot['employee']['name'], 'period' => $r->run->period->name, 'run_id' => $r->payroll_run_id, 'remaining' => Money::normalize($r->remaining)])->all(), 'qualification' => 'At most 20 unpaid employee salary obligations from posted payroll. No payment is initiated.'];
    }

    public function reply(string $intent, string $id, bool $sq): array
    {
        $base = ['id' => (string) Str::uuid(), 'conversation_id' => $id, 'intent' => 'workforce', 'cards' => [], 'sources' => [], 'limitations' => [], 'choices' => [], 'provider' => 'deterministic', 'read_only' => true, 'context' => null, 'state' => 'complete'];
        if ($intent === 'boundary') {
            return [...$base, 'text' => $sq ? 'AIMS mund të shpjegojë të dhënat e autorizuara. Pagat, pagesat, pushimet dhe vendimet e punësimit kërkojnë veprim njerëzor në hapësirën përkatëse.' : 'AIMS can explain authorized workforce data. Payroll, payments, leave approvals and employment decisions require a person to act in the appropriate workspace.'];
        }
        if (! app(WorkforceService::class)->can(self::TOOLS[$intent])) {
            return [...$base, 'state' => 'permission_denied', 'text' => $sq ? 'Nuk keni leje për këto të dhëna të personelit ose pagave.' : 'You do not have permission to view this workforce or payroll information.'];
        }
        $data = app(AimsToolRegistry::class)->execute($intent, [])['data'];
        $metrics = [];
        $cards = [];
        $url = '/workforce';
        if ($intent === 'get_payroll_summary') {
            $run = $data['latest_run'];
            $url = '/payroll'.($run ? '?run='.$run->id : '');
            $text = $run ? ($sq ? 'Lista më e fundit e pagave: ' : 'Latest payroll: ').$run->period->name.' · '.$run->status : ($sq ? 'Nuk ka ende listë pagash.' : 'No payroll has been prepared.');
            if ($run) {
                foreach (['gross' => 'Gross / Bruto', 'net' => 'Net / Neto', 'employer_cost' => 'Employer cost / Kostoja'] as $key => $label) {
                    $metrics[] = ['label' => $label, 'value' => $run->totals[$key] ?? null, 'unit' => 'EUR'];
                }
            }
        } elseif ($intent === 'get_unpaid_payroll') {
            $url = '/payroll';
            $text = $sq ? 'Pagat e papaguara nga listat e postuara.' : 'Unpaid salaries from posted payroll.';
            foreach ($data['items'] as $r) {
                $cards[] = ['tool' => $intent, 'title' => $r['name'], 'summary' => $r['period'], 'url' => '/payroll?run='.$r['run_id'], 'metrics' => [['label' => $sq ? 'Mbetja' : 'Remaining', 'value' => $r['remaining'], 'unit' => 'EUR']]];
            }
        } elseif ($intent === 'get_workforce_summary') {
            $text = $data['active_employees'].($sq ? ' punonjës aktivë ose në pushim.' : ' employees are active or on leave.');
        } else {
            $text = $sq ? 'Kërkesa që presin miratim njerëzor.' : 'Requests waiting for human approval.';
            foreach ($data['requests'] as $r) {
                $metrics[] = ['label' => $r['kind'], 'value' => $r['count'], 'unit' => 'count'];
            } $url = '/workforce?tab=records';
            foreach ($data['items'] as $r) {
                $cards[] = ['tool' => $intent, 'title' => $r['employee']['name'], 'summary' => $r['kind'].' · '.substr($r['starts_on'], 0, 10), 'url' => '/workforce?tab=records&employee='.$r['employee_id'], 'metrics' => [['label' => 'Units / Njësi', 'value' => $r['units'], 'unit' => 'count']]];
            }
        }

        return [...$base, 'text' => $text, 'cards' => [['tool' => $intent, 'title' => $sq ? 'Personeli dhe pagat' : 'Workforce and payroll', 'summary' => $text, 'url' => $url, 'metrics' => $metrics], ...$cards], 'sources' => [['tool' => $intent, 'url' => $url]], 'limitations' => [$data['qualification'] ?? 'Confidential, read-only payroll evidence. No EDI filing or employment action.']];
    }
}
