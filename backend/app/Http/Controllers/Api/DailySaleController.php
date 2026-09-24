<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailySale;
use App\Services\DailySaleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class DailySaleController extends Controller
{
    public function __construct(private readonly DailySaleService $dailySales) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:draft,finalized'],
            'source' => ['nullable', 'in:manual,order'],
        ]);

        return response()->json($this->dailySales->list($validated));
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        return response()->json($this->dailySales->todaySummary($validated['date'] ?? null));
    }

    public function dayNotes(Request $request): JsonResponse
    {
        $validated = $request->validate(['date' => ['required', 'date']]);

        return response()->json([
            'date' => $validated['date'],
            'notes' => $this->dailySales->dayNotes($validated['date']),
        ]);
    }

    public function updateDayNotes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return response()->json([
            'date' => $validated['date'],
            'notes' => $this->dailySales->updateDayNotes($validated['date'], $validated['notes'] ?? null),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, true);

        return response()->json($this->dailySales->create($validated), 201);
    }

    public function show(DailySale $dailySale): JsonResponse
    {
        return response()->json($this->dailySales->find($dailySale->id));
    }

    public function update(Request $request, DailySale $dailySale): JsonResponse
    {
        $validated = $this->validatePayload($request);

        return response()->json($this->dailySales->update($dailySale, $validated));
    }

    public function finalize(DailySale $dailySale): JsonResponse
    {
        return response()->json($this->dailySales->finalize($dailySale));
    }

    public function finalizeDay(Request $request): JsonResponse
    {
        $validated = $request->validate(['date' => ['required', 'date']]);

        return response()->json($this->dailySales->finalizeDay($validated['date']));
    }

    public function destroy(DailySale $dailySale): JsonResponse
    {
        $this->dailySales->destroy($dailySale);

        return response()->json(['message' => 'Daily sales sheet deleted.']);
    }

    public function downloadPdf(Request $request, DailySale $dailySale): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['nullable', 'in:en,sq'],
        ]);

        $locale = $validated['locale'] ?? 'en';
        $sale = $this->dailySales->find($dailySale->id);
        $user = Auth::user()->load('company');
        $labels = $this->pdfLabels($locale);

        $pdf = Pdf::loadView('daily-sales.pdf', [
            'sale' => $sale,
            'company' => $user->company,
            'labels' => $labels,
            'locale' => $locale,
        ]);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions([
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'defaultFont' => 'sans-serif',
        ]);

        return response()->json([
            'pdf' => base64_encode($pdf->output()),
            'filename' => "daily-sales-{$sale->sale_number}.pdf",
        ]);
    }

    public function downloadDayPdf(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'locale' => ['nullable', 'in:en,sq'],
        ]);
        $locale = $validated['locale'] ?? 'en';
        $sales = $this->dailySales->list(['date' => $validated['date']]);
        $user = Auth::user()->load('company');
        $labels = $this->pdfLabels($locale);
        $pdf = Pdf::loadView('daily-sales.day-pdf', [
            'sales' => $sales,
            'date' => $validated['date'],
            'company' => $user->company,
            'labels' => $labels,
            'locale' => $locale,
        ]);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true, 'defaultFont' => 'sans-serif']);

        return response()->json([
            'pdf' => base64_encode($pdf->output()),
            'filename' => "daily-sales-{$validated['date']}.pdf",
        ]);
    }

    private function validatePayload(Request $request, bool $creating = false): array
    {
        return $request->validate([
            'idempotency_key' => [$creating ? 'nullable' : 'sometimes', 'uuid'],
            'sale_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'signature_name' => ['nullable', 'string', 'max:255'],
            'allow_expired_override' => ['nullable', 'boolean'],
            'expired_override_reason' => ['nullable', 'required_if:allow_expired_override,true', 'string', 'min:5', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', Auth::user()->company_id)],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.actual_base_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'items.*.warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', Auth::user()->company_id)],
            'items.*.location_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('company_id', Auth::user()->company_id)],
            'items.*.trace_allocations' => ['nullable', 'array', 'max:1000'],
            'items.*.trace_allocations.*.inventory_lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')->where('company_id', Auth::user()->company_id)],
            'items.*.trace_allocations.*.serial_number' => ['nullable', 'string', 'max:191'],
            'items.*.trace_allocations.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.trace_allocations.*.expiry_at' => ['nullable', 'date'],
            'items.*.trace_allocations.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);
    }

    private function pdfLabels(string $locale): array
    {
        $catalog = [
            'en' => [
                'title' => 'Daily Sales',
                'date' => 'Date',
                'number' => 'Daily Sales No.',
                'customer' => 'Sale',
                'day_total' => 'Daily Total',
                'no' => 'No.',
                'product' => 'Product Name / Service',
                'unit' => 'Unit',
                'quantity' => 'Quantity',
                'price' => 'Price',
                'amount' => 'Amount',
                'total' => 'Total Amount',
                'notes' => 'Notes',
                'signature' => 'Signature',
                'draft' => 'DRAFT',
                'finalized' => 'FINALIZED',
            ],
            'sq' => [
                'title' => 'Shitja Ditore',
                'date' => 'Data',
                'number' => 'Nr. i Shitjes Ditore',
                'customer' => 'Shitja',
                'day_total' => 'Totali Ditor',
                'no' => 'Nr.',
                'product' => 'Emri i Produktit / Shërbimit',
                'unit' => 'Njësia',
                'quantity' => 'Sasia',
                'price' => 'Çmimi',
                'amount' => 'Vlera',
                'total' => 'Shuma Totale',
                'notes' => 'Shënime',
                'signature' => 'Nënshkrimi',
                'draft' => 'DRAFT',
                'finalized' => 'FINALIZUAR',
            ],
        ];

        return $catalog[$locale] ?? $catalog['en'];
    }
}
