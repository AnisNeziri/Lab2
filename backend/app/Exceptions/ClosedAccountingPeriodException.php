<?php

namespace App\Exceptions;

use App\Models\AccountingPeriod;
use Illuminate\Validation\ValidationException;

class ClosedAccountingPeriodException extends ValidationException
{
    public int $periodId;

    public string $postingDate;

    public string $periodState;

    public static function forPeriod(AccountingPeriod $period, string $date): self
    {
        $e = static::withMessages(['posting_date' => ["Accounting period {$period->name} is {$period->status}; {$date} cannot be posted. Use an authorized period adjustment or reopening workflow."]]);
        $e->periodId = $period->id;
        $e->postingDate = $date;
        $e->periodState = $period->status;

        return $e;
    }
}
