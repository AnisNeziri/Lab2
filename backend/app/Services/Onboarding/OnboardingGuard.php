<?php

namespace App\Services\Onboarding;

use App\Models\OnboardingSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal capability, never derived from an HTTP payload. */
class OnboardingGuard
{
    private ?int $sessionId = null;

    public function isPosting(): bool
    {
        return $this->sessionId !== null;
    }

    public function run(OnboardingSession $session, \Closure $callback): mixed
    {
        $this->assertOpening($session);
        $previous = $this->sessionId;
        $this->sessionId = $session->id;
        try {
            return $callback();
        } finally {
            $this->sessionId = $previous;
        }
    }

    public function assertOpening(OnboardingSession $session): void
    {
        if ((int) $session->company_id !== (int) auth()->user()?->company_id || ! in_array($session->status, ['posting', 'posting_failed'], true) || ! $session->approved_fingerprint || ! $session->posting_started_at) {
            throw ValidationException::withMessages(['onboarding' => 'An approved posting session is required.']);
        }
    }

    public function assertOperational(?string $date = null, ?int $explicitCompany = null): void
    {
        $companyId = auth()->user()?->company_id ?? $explicitCompany;
        if (! $companyId) {
            return;
        }
        if ($explicitCompany && auth()->user()?->company_id && (int) $explicitCompany !== (int) $companyId) {
            abort(403);
        }
        // Hold the parent lock through the authoritative transaction so the
        // opening cannot begin between this check and its ledger writes.
        $inTransaction = DB::transactionLevel() > 0;
        $companyQuery = DB::table('companies')->where('id', $companyId);
        $company = $inTransaction ? $companyQuery->sharedLock()->first() : $companyQuery->first();
        $activeQuery = OnboardingSession::withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('posting_started_at')->whereNotIn('status', ['locked', 'cancelled']);
        $active = $inTransaction ? $activeQuery->sharedLock()->first() : $activeQuery->first();
        if ($active && $active->id !== $this->sessionId) {
            throw ValidationException::withMessages(['onboarding' => 'Opening posting is in progress. Verify and lock onboarding before normal operations.']);
        }
        $cutover = $company?->operational_cutover_date;
        if ($date && $cutover && substr($date, 0, 10) <= substr((string) $cutover, 0, 10)) {
            throw ValidationException::withMessages(['date' => 'This date belongs to the locked opening. Use a later correction or reversal date.']);
        }
    }
}
