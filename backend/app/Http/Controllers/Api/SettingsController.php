<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\UserPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'preferences' => UserPreferences::normalize($user->preferences),
            'company' => [
                'base_currency' => $user->company?->base_currency ?: 'EUR',
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['sometimes', 'in:light,dark'],
            'language' => ['sometimes', 'in:en,sq'],
            'enable_3d_map' => ['sometimes', 'boolean'],
            'base_currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
        ]);

        $user = Auth::user();
        if (array_key_exists('base_currency', $validated)) {
            abort_unless($user->role === 'admin', 403, 'Only a company administrator may change the base currency.');
            $currency = strtoupper($validated['base_currency']);
            unset($validated['base_currency']);
            DB::transaction(function () use ($user, $currency): void {
                // Product and supplier creation take the same company-row
                // lock, so the first business record cannot race this setting.
                $company = $user->company()->lockForUpdate()->firstOrFail();
                if ($currency !== strtoupper((string) ($company->base_currency ?: 'EUR'))) {
                    foreach (['products', 'product_suppliers', 'landed_costs', 'purchase_orders', 'stock_movements', 'daily_sales', 'invoices', 'expenses'] as $table) {
                        if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')
                            && DB::table($table)->where('company_id', $company->id)->exists()) {
                            throw ValidationException::withMessages([
                                'base_currency' => ['Base currency is locked after company inventory or financial history exists.'],
                            ]);
                        }
                    }
                    $company->update(['base_currency' => $currency]);
                }
            });
        }
        $preferences = UserPreferences::normalize($user->preferences);
        $preferences = array_merge($preferences, $validated);
        $user->preferences = $preferences;
        $user->save();

        return response()->json([
            'preferences' => $preferences,
            'company' => [
                'base_currency' => $user->company?->fresh()?->base_currency ?: 'EUR',
            ],
        ]);
    }
}
