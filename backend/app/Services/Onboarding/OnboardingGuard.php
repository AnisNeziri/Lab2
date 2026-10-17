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

    public function assertOperational(?string $date = null): void
    {
        $companyId = auth()->user()?->company_id;
        if (! $companyId) {
            return;
        }
        // Hold the parent lock through the authoritative transaction so the
        // opening cannot begin between this check and its ledger writes.
        if (DB::transactionLevel() > 0) {
            DB::table('companies')->where('id', $companyId)->sharedLock()->first();
        }
        $active = OnboardingSession::where('company_id', $companyId)->whereNotNull('posting_started_at')->whereNotIn('status', ['locked', 'cancelled'])->first();
        if ($active && $active->id !== $this->sessionId) {
            throw ValidationException::withMessages(['onboarding' => 'Opening posting is in progress. Verify and lock onboarding before normal operations.']);
        }
        $cutover = auth()->user()->company?->operational_cutover_date;
        if ($date && $cutover && substr($date, 0, 10) <= $cutover->toDateString()) {
            throw ValidationException::withMessages(['date' => 'This date belongs to the locked opening. Use a later correction or reversal date.']);
        }
    }
}
