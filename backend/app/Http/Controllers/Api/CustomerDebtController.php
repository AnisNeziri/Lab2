<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerDebt;
use App\Models\CustomerDebtEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerDebtController extends Controller
{
    public function index()
    {
        return CustomerDebt::with('entries')->latest()->get()->map(fn ($d) => ['id' => $d->id, 'customer_name' => $d->customer_name, 'balance' => $d->balance, 'entries' => $d->entries]);
    }

    public function store(Request $r)
    {
        $d = $r->validate(['customer_name' => ['required', 'string', 'max:255'], 'entry_date' => ['required', 'date'], 'description' => ['required', 'string', 'max:255'], 'amount_owed' => ['required', 'numeric', 'min:0'], 'amount_paid' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:2000']]);
        if (($d['amount_owed'] ?? 0) <= 0 && ($d['amount_paid'] ?? 0) <= 0) {
            abort(422, 'Enter an amount owed or paid.');
        }

return DB::transaction(function () use ($d) {
            $debt = CustomerDebt::firstOrCreate(['company_id' => Auth::user()->company_id, 'customer_name' => $d['customer_name']]);
            $debt->entries()->create($d);

            return $debt->fresh('entries');
        });
    }

    public function pay(Request $r, CustomerDebt $customerDebt)
    {
        $d = $r->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'note' => ['nullable', 'string', 'max:500'], 'entry_date' => ['nullable', 'date']]);
        if ($d['amount'] > $customerDebt->balance) {
            abort(422, 'Payment exceeds the customer debt.');
        } CustomerDebtEntry::create(['customer_debt_id' => $customerDebt->id, 'entry_date' => $d['entry_date'] ?? now(), 'description' => 'Payment received', 'amount_owed' => 0, 'amount_paid' => $d['amount'], 'notes' => $d['note'] ?? null]);

        return $customerDebt->fresh('entries');
    }

    public function destroy(CustomerDebt $customerDebt)
    {
        if ($customerDebt->balance > 0) {
            abort(422, 'Record the remaining payment before deleting this debt sheet.');
        }$customerDebt->delete();

        return response()->noContent();
    }
}
