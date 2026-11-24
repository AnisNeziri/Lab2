<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetEntry;
use App\Models\Invoice;
use App\Services\FixedAssetService;
use App\Support\CompanyClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetController extends Controller
{
    public function __construct(private readonly FixedAssetService $assets) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['as_of' => 'nullable|date_format:Y-m-d', 'search' => 'nullable|string|max:120']);
        $to = $filters['as_of'] ?? CompanyClock::today()->toDateString();
        $page = FixedAsset::query()->with('category')->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $search)->orWhere('asset_number', 'like', $search));
        })->orderBy('asset_number')->paginate(20)->through(function (FixedAsset $asset) use ($to) {
            $balance = $this->assets->balance($asset, $to);

            return [...$asset->toArray(), ...$balance, 'depreciation_due' => $this->assets->depreciationDue($asset, $to, $balance)];
        });

        return response()->json([
            'assets' => $page, 'categories' => FixedAssetCategory::query()->orderBy('name')->get(),
            'reconciliation' => $this->assets->reconciliation($to),
        ]);
    }

    public function show(Request $request, FixedAsset $asset): JsonResponse
    {
        $to = $request->validate(['as_of' => 'nullable|date_format:Y-m-d'])['as_of'] ?? CompanyClock::today()->toDateString();
        $asset->load('category');
        $balance = $this->assets->balance($asset, $to);

        return response()->json([
            ...$asset->toArray(), ...$balance, 'as_of' => $to, 'depreciation_due' => $this->assets->depreciationDue($asset, $to, $balance),
            'entries' => $asset->entries()->with(['expense:id,document_number,vendor_name,status', 'saleInvoice:id,invoice_number,status,grand_total,total_paid', 'journal:id,journal_number,status'])
                ->where('effective_on', '<=', $to)->orderByDesc('effective_on')->orderByDesc('id')->paginate(20)->through(function (FixedAssetEntry $entry) {
                    $entry->expense?->setAppends([]);
                    $entry->saleInvoice?->setAppends([]);

                    return $entry;
                }),
        ]);
    }

    public function category(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120', 'minimum_cost' => 'required|numeric|min:0|max:99999999999999|decimal:0,2',
            'useful_life_months' => 'required|integer|between:1,1200', 'policy_reference' => 'required|string|max:500',
        ]);

        return response()->json($this->assets->approveCategory($data), 201);
    }

    public function prepare(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate([
            'fixed_asset_id' => 'nullable|integer|min:1', 'category_id' => 'required_without:fixed_asset_id|integer|min:1',
            'asset_number' => 'required_without:fixed_asset_id|string|max:60', 'name' => 'required_without:fixed_asset_id|string|max:160',
            'residual_value' => 'required_without:fixed_asset_id|numeric|min:0|max:99999999999999|decimal:0,2',
            'reason' => 'required|string|min:5|max:1000',
        ]);

        return response()->json($this->assets->prepareBill($expense, $data), 201);
    }

    public function withdraw(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $this->assets->withdrawBill($expense, $data['reason']);

        return response()->json(null, 204);
    }

    public function placeInService(Request $request, FixedAsset $asset): JsonResponse
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:5|max:1000']);

        return response()->json($this->assets->placeInService($asset, $data['date'], $data['reason']));
    }

    public function prepareSale(Request $request, FixedAsset $asset): JsonResponse
    {
        $data = $request->validate(['invoice_id' => 'required|integer|min:1', 'reason' => 'required|string|min:5|max:1000']);
        $invoice = Invoice::query()->findOrFail($data['invoice_id']);

        return response()->json($this->assets->prepareSale($asset, $invoice, $data['reason']), 201);
    }

    public function withdrawSale(Request $request, FixedAssetEntry $assetEntry): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $this->assets->withdrawSale($assetEntry, $data['reason']);

        return response()->json(null, 204);
    }

    public function post(Request $request, FixedAsset $asset): JsonResponse
    {
        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d', 'kind' => 'required|in:depreciation,impairment,write_off',
            'amount' => 'required_if:kind,impairment|prohibited_unless:kind,impairment|numeric|gt:0|max:99999999999999|decimal:0,2',
            'reason' => 'required|string|min:5|max:1000', 'request_key' => 'required|string|max:80',
        ]);

        return response()->json($this->assets->post($asset, $data), 201);
    }

    public function reverse(Request $request, FixedAssetEntry $assetEntry): JsonResponse
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:5|max:1000']);

        return response()->json($this->assets->reverse($assetEntry, $data['date'], $data['reason']), 201);
    }
}
