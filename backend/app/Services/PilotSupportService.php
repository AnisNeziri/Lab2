<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PilotSupportService
{
    public function reference(Request $request): string
    {
        if (! $request->attributes->has('support_reference')) {
            $request->attributes->set('support_reference', 'AIMS-'.bin2hex(random_bytes(8)));
        }

        return $request->attributes->get('support_reference');
    }

    public function record(\Throwable $exception, Request $request, int $status, bool $writeLog = true): string
    {
        $reference = $this->reference($request);
        $safe = ['reference' => $reference, 'route_key' => substr($request->route()?->uri() ?: 'api/unmatched', 0, 100),
            'exception_type' => substr(class_basename($exception), 0, 80), 'http_status' => $status,
            'company_id' => Auth::user()?->company_id, 'user_id' => Auth::id()];
        if ($writeLog) {
            Log::error('AIMS request failed.', $safe);
        }
        if ($safe['company_id'] && (int) config('pilot.company_id') === (int) $safe['company_id']) {
            try {
                DB::table('pilot_incidents')->insertOrIgnore($safe + ['occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            } catch (\Throwable) { /* Database outage: the safe file log above retains the reference. */
            }
        }

        return $reference;
    }
}
