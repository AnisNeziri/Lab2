<?php

namespace App\Services;

use App\Models\{Product, ProductSupplier, PurchaseOrder, PurchaseRequest, Rfq, Supplier, SupplierQuoteItem, Warehouse};
use App\Support\{CompanyClock, CompanyCurrency, Money};
use Illuminate\Validation\ValidationException;

/** Read-only decision view; procurement, valuation and payment remain with their existing services. */
final class PurchaseComparisonService
{
    public const PERMISSIONS = ['procurement.view', 'inventory.view'];
    public const TOOL = 'get_purchase_comparison';
    public const PORTFOLIO_TOOL = 'get_purchase_portfolio';
    private const SUPPLIER_LIMIT = 8;
    private const QUOTE_LIMIT = 80;

    public function allowed(): bool
    {
        return (bool) auth()->user()?->company_id && collect(self::PERMISSIONS)->every(fn ($permission) => $this->can($permission));
    }

    private function can(string $permission): bool
    {
        return app(PermissionService::class)->roleHasPermission(auth()->user()->role, $permission);
    }

    public function compare(array $input): array
    {
        return app(PermissionService::class)->readScope(fn () => array_key_exists('lines', $input)
            ? \Illuminate\Support\Facades\DB::transaction(fn () => $this->portfolio($input)) : $this->read($input));
    }

    private function portfolio(array $input): array
    {
        abort_unless($this->budgetAllowed(), 403);
        if (app(Security\SessionSecurity::class)->policy()['finance_passkey_required']) {
            app(Security\SessionSecurity::class)->recent(true);
        }
        $v = validator($input, [
            'lines' => 'required|array|list|min:1|max:5',
            'lines.*' => 'required|array:product_id,quantity,unit,warehouse_id,supplier_id,required_by,priority,assumed_unit_price,assumed_extra_cost',
            'lines.*.product_id' => 'required|integer|distinct|min:1',
            'budget' => 'nullable|numeric|min:0|max:100000000|decimal:0,2',
            'buffer' => 'nullable|numeric|min:0|max:100000000|decimal:0,2',
        ])->validate();
        $lines = array_map(fn ($line) => $this->read($line, false), $v['lines']);
        $currency = CompanyCurrency::current();
        $financial = app(PurchasePortfolioFinance::class)->read($currency);
        $envelope = $v['budget'] ?? null;
        $buffer = Money::normalize($v['buffer'] ?? 0);
        $strategic = collect($lines)->contains(fn ($line) => $this->hasQualifiedNeed($line));
        $cases = [];
        foreach (['conservative', 'balanced', 'growth'] as $index => $id) {
            $rows = [];
            foreach ($lines as $line) {
                $options = array_slice($line['scenarios'], 0, $index + 1);
                $qualified = $strategic && $this->hasQualifiedNeed($line);
                $choices = $qualified ? collect($options)->filter(fn ($option) => $option['base_quantity'] <= $line['firm_shortfall_now']) : collect();
                $option = $strategic ? $choices->last() : $line['scenarios'][$index];
                $rows[] = [
                    'product' => $line['product'], 'unit' => $line['normalized']['unit'],
                    'quantity' => $option['quantity'] ?? null, 'base_quantity' => $option['base_quantity'] ?? null,
                    'cost' => $option['material_cost'] ?? null, 'cost_source' => $option['cost_source'] ?? null,
                    'unit_price' => $option['price_per_purchase_unit'] ?? null,
                    'price_evidence' => $option['price_evidence'] ?? null,
                    'assumed_extra_cost' => $option['assumed_extra_cost'] ?? null,
                    'supplier_id' => $option['supplier_id'] ?? null,
                    'reason' => !$strategic ? 'quantity_sensitivity' : ($option ? 'confirmed_order_shortfall' : 'needs_separate_review'),
                ];
            }
            $included = collect($rows)->whereNotNull('quantity');
            $unpriced = $included->whereNull('cost')->count();
            $known = Money::add(...$included->whereNotNull('cost')->pluck('cost')->all());
            $subtotals = [];
            foreach (['quote', 'catalogue', 'assumption'] as $source) {
                $matching = $included->where('cost_source', $source);
                $subtotals[$source] = ['count' => $matching->count(), 'amount' => Money::add(...$matching->pluck('cost')->all())];
            }
            $extras = Money::add(...$included->whereNotNull('assumed_extra_cost')->pluck('assumed_extra_cost')->all());
            $pricedSubtotal = Money::add($known, $extras);
            $completeMaterials = !$unpriced && $included->isNotEmpty();
            $hasAssumptions = $subtotals['assumption']['count'] > 0 || $included->whereNotNull('assumed_extra_cost')->isNotEmpty();
            $cases[] = [
                'id' => $id, 'lines' => $rows,
                'known_material_cost' => Money::add($subtotals['quote']['amount'], $subtotals['catalogue']['amount']),
                'material_subtotals' => $subtotals, 'assumed_extras_subtotal' => $extras,
                'has_assumptions' => $hasAssumptions,
                'priced_subtotal' => $included->count() > $unpriced || $hasAssumptions ? $pricedSubtotal : null,
                'material_total' => $completeMaterials ? $known : null,
                'unpriced_count' => $unpriced, 'priced_count' => $included->count() - $unpriced,
                'remaining_envelope_before_extras' => $envelope === null || !$completeMaterials || $hasAssumptions ? null : Money::subtract($envelope, $known),
                'illustrative_envelope_remaining' => $envelope === null || !$completeMaterials ? null : Money::subtract($envelope, $pricedSubtotal),
                'priced_costs_exceed_envelope' => $envelope !== null && Money::compare($pricedSubtotal, $envelope) > 0,
                'known_materials_exceed_envelope' => $envelope !== null && Money::compare(Money::add($subtotals['quote']['amount'], $subtotals['catalogue']['amount']), $envelope) > 0,
                'recorded_cash_less_obligations_buffer_and_known_materials' => $hasAssumptions || $financial['planning_cash_after_obligations'] === null
                    ? null : Money::subtract(Money::subtract($financial['planning_cash_after_obligations'], $buffer), $known),
                'illustrative_cash_after_buffer_and_priced_costs' => $financial['planning_cash_after_obligations'] === null || !$completeMaterials
                    ? null : Money::subtract(Money::subtract($financial['planning_cash_after_obligations'], $buffer), $pricedSubtotal),
            ];
        }

        return ['read_only' => true, 'base_currency' => $currency, 'lines' => $lines, 'scenarios' => $cases,
            'strategy' => $strategic ? 'confirmed_shortfall_review' : 'quantity_sensitivity', 'budget' => $envelope,
            'buffer' => $buffer, 'financial' => $financial, 'url' => '/procurement'];
    }

    private function hasQualifiedNeed(array $line): bool
    {
        return $line['stock']['incoming_quantity_complete'] && $line['stock']['incoming'] <= 0
            && $line['firm_shortfall_now'] >= $line['scenarios'][0]['base_quantity'];
    }

    public function budgetAllowed(): bool
    {
        return $this->allowed() && collect(['analytics.finance', 'finance.view', 'financial_accounts.view', 'purchase_orders.view'])
            ->every(fn ($permission) => $this->can($permission));
    }

    private function read(array $input, bool $withFinance = true): array
    {
        abort_unless($this->allowed(), 403);
        $today = CompanyClock::today();
        $v = validator($input, [
            'product_id' => 'required|integer|min:1', 'warehouse_id' => 'nullable|integer|min:1',
            'quantity' => 'required|numeric|gt:0|max:100000000|decimal:0,3', 'unit' => 'nullable|string|max:32',
            'required_by' => 'nullable|date_format:Y-m-d|after_or_equal:'.$today->toDateString(),
            'priority' => 'nullable|in:cost,speed,reliability,balanced',
            'supplier_id' => 'nullable|integer|min:1', 'budget' => 'nullable|numeric|min:0|max:100000000|decimal:0,2',
            'assumed_unit_price' => 'nullable|numeric|min:0|max:1000000|decimal:0,4',
            'assumed_extra_cost' => 'nullable|numeric|min:0|max:100000000|decimal:0,2',
            'scenario_quantities' => 'nullable|array:conservative,balanced,growth',
            'scenario_quantities.*' => 'required|numeric|gt:0|max:100000000|decimal:0,3',
        ])->validate();
        $company = (int) auth()->user()->company_id;
        $product = Product::where('company_id', $company)->findOrFail($v['product_id']);
        $warehouse = isset($v['warehouse_id'])
            ? Warehouse::where('company_id', $company)->where('is_active', true)->findOrFail($v['warehouse_id']) : null;
        $selected = isset($v['supplier_id'])
            ? Supplier::where('company_id', $company)->where('is_active', true)->findOrFail($v['supplier_id']) : null;
        $money = $this->can('analytics.finance') || $this->can('supplier_catalogue.view');
        $canSeeOrders = $this->can('purchase_orders.view');
        $finance = $this->budgetAllowed();
        if ($finance && $withFinance && app(Security\SessionSecurity::class)->policy()['finance_passkey_required']) {
            app(Security\SessionSecurity::class)->recent(true);
        }
        abort_if((isset($v['budget']) || isset($v['assumed_unit_price']) || isset($v['assumed_extra_cost'])) && !$finance, 403);
        $units = app(UnitConversionService::class);
        $normalized = $units->resolve($product, (float) $v['quantity'], $v['unit'] ?? null);
        $quantity = $normalized['base_quantity'];
        $currency = CompanyCurrency::current();

        $catalogue = ProductSupplier::where('company_id', $company)->where('product_id', $product->id)
            ->where('is_active', true)->whereIn('supplier_id', Supplier::where('company_id', $company)->where('is_active', true)->select('id'))
            ->orderByDesc('is_preferred')->orderBy('id')->limit(self::SUPPLIER_LIMIT + 1)->get();
        $quotes = SupplierQuoteItem::with(['supplierQuote', 'requestItem'])
            ->whereHas('requestItem', fn ($q) => $q->where('product_id', $product->id)
                ->whereIn('purchase_request_id', PurchaseRequest::where('company_id', $company)->select('id')))
            ->whereHas('supplierQuote', fn ($q) => $q->where('company_id', $company)->where('status', 'received')
                ->whereDate('valid_until', '>=', $today->toDateString())
                ->whereIn('supplier_id', Supplier::where('company_id', $company)->where('is_active', true)->select('id'))
                ->whereIn('rfq_id', Rfq::where('company_id', $company)->whereIn('status', ['responses_received', 'evaluated', 'partially_awarded'])->select('id'))
                ->whereNotExists(fn ($new) => $new->selectRaw('1')->from('supplier_quotes as newer')
                    ->whereColumn('newer.company_id', 'supplier_quotes.company_id')->whereColumn('newer.rfq_id', 'supplier_quotes.rfq_id')
                    ->whereColumn('newer.supplier_id', 'supplier_quotes.supplier_id')->whereColumn('newer.revision', '>', 'supplier_quotes.revision')))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('procurement_awards as awards')
                ->join('supplier_quotes as considered', 'considered.rfq_id', '=', 'awards.rfq_id')
                ->whereColumn('awards.company_id', 'considered.company_id')
                ->whereColumn('considered.id', 'supplier_quote_items.supplier_quote_id')
                ->whereColumn('awards.purchase_request_item_id', 'supplier_quote_items.purchase_request_item_id'))
            ->latest('id')->limit(self::QUOTE_LIMIT + 1)->get();
        $rfqs = Rfq::where('company_id', $company)->whereIn('id', $quotes->pluck('supplierQuote.rfq_id')->unique())->get()->keyBy('id');
        $quoteScanLimited = $quotes->count() > self::QUOTE_LIMIT;
        $quotes = $quotes->filter(fn ($line) => $line->requestItem && $rfqs->get($line->supplierQuote->rfq_id)?->purchase_request_id === $line->requestItem->purchase_request_id)->values();
        $activeSuppliers = Supplier::where('company_id', $company)->where('is_active', true);
        $historyIds = $canSeeOrders ? PurchaseOrder::where('company_id', $company)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereIn('supplier_id', (clone $activeSuppliers)->select('id'))
            ->whereHas('items', fn ($items) => $items->where('product_id', $product->id))
            ->selectRaw('supplier_id, MAX(id) AS recent_order')->groupBy('supplier_id')
            ->orderByDesc('recent_order')->limit(self::SUPPLIER_LIMIT + 1)->pluck('supplier_id') : collect();
        $preferred = $product->supplier_id && (clone $activeSuppliers)->whereKey($product->supplier_id)->exists()
            ? [$product->supplier_id] : [];
        $supplierIds = $quotes->pluck('supplierQuote.supplier_id')->merge($catalogue->pluck('supplier_id'))->merge($preferred)->merge($historyIds)->unique()->values();
        $limited = $supplierIds->count() > self::SUPPLIER_LIMIT || $quoteScanLimited;
        $suppliers = Supplier::where('company_id', $company)->where('is_active', true)
            ->whereIn('id', $supplierIds->take(self::SUPPLIER_LIMIT))->orderBy('name')->get();
        if ($selected && !$suppliers->contains('id', $selected->id)) {
            throw ValidationException::withMessages(['supplier_id' => 'Choose a supplier in this comparison.']);
        }
        $catalogue = $catalogue->keyBy('supplier_id');
        $quotes = $quotes->take(self::QUOTE_LIMIT)->groupBy('supplierQuote.supplier_id');
        $recordingRequests = collect();
        if ($suppliers->isNotEmpty() && $this->can('procurement.manage')) {
            $recordingRequests = Rfq::query()
                ->join('rfq_suppliers as invited', 'invited.rfq_id', '=', 'rfqs.id')
                ->join('purchase_request_items as requested', 'requested.purchase_request_id', '=', 'rfqs.purchase_request_id')
                ->where('rfqs.company_id', $company)
                ->whereIn('rfqs.status', ['draft', 'issued', 'responses_received', 'evaluated', 'partially_awarded'])
                ->whereIn('invited.supplier_id', $suppliers->modelKeys())
                ->where('requested.product_id', $product->id)
                ->where('requested.unit', $normalized['unit'])->where('requested.quantity', $normalized['purchase_quantity'] ?? $v['quantity'])
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('procurement_awards as awards')
                    ->whereColumn('awards.rfq_id', 'rfqs.id')->whereColumn('awards.purchase_request_item_id', 'requested.id'))
                ->selectRaw('invited.supplier_id, MAX(rfqs.id) as eligible_rfq_id')
                ->groupBy('invited.supplier_id')->pluck('eligible_rfq_id', 'invited.supplier_id');
        }
        $history = app(SupplierHistoryService::class);
        $rows = [];
        foreach ($suppliers as $supplier) {
            $mapping = $catalogue->get($supplier->id);
            $offers = $quotes->get($supplier->id, collect());
            $quote = $this->quote($offers, $product, $quantity, $currency, $today->toDateString());
            $orders = $canSeeOrders
                ? array_values(array_filter($history->orders($supplier), fn ($o) => collect($o['lines'])->contains('product_id', $product->id))) : [];
            $distribution = $history->distribution($orders, $product->id);
            $latest = collect($orders)->whereNotIn('status', ['draft', 'cancelled'])->take(3);
            $assessed = collect($orders)->where('complete', true)->whereNotNull('lead_days')
                ->whereNotNull('late_days')->where('label_known_at', '<=', $today->toDateString());
            $distribution['assessed_from'] = $assessed->min('completion_date');
            $distribution['assessed_to'] = $assessed->max('completion_date');
            $lines = $latest->flatMap(fn ($order) => collect($order['lines'])->where('product_id', $product->id)->where('unit', $normalized['inventory_unit']));
            $inspections = $latest->flatMap(fn ($order) => collect($order['inspections'])->where('product_id', $product->id));
            $lead = $quote['lead_days'] ?? null;
            $arrival = $lead !== null ? $today->addDays($lead)->toDateString() : null;
            $comparable = $quote && $quote['total'] !== null
                && (!isset($v['required_by']) || ($arrival && $arrival <= $v['required_by']));
            $pack = (int) round((float) ($mapping?->pack_size ?? 0) * 1000);
            $requiredUnits = max((int) round($quantity * 1000), (int) round((float) ($mapping?->minimum_order_quantity ?? 0) * 1000));
            $packQuantity = ($pack > 0 ? intdiv($requiredUnits + $pack - 1, $pack) * $pack : $requiredUnits) / 1000;
            $rows[] = [
                'id' => $supplier->id, 'name' => $supplier->name, 'catalogue_linked' => (bool) $mapping,
                'quote_recording_url' => $recordingRequests->has($supplier->id)
                    ? '/procurement?rfq='.$recordingRequests[$supplier->id].'&supplier='.$supplier->id.'#received-supplier-quote' : null,
                'product_supplier' => (int) $product->supplier_id === (int) $supplier->id,
                'evidence_type' => $comparable ? ($money ? 'comparable_offer' : 'recorded_offer')
                    : ($latest->isNotEmpty() ? 'historical_candidate' : 'registered_supplier'),
                'quote_gaps' => array_values(array_filter([
                    !$quote ? 'current_quote' : null,
                    $money && $quote && $quote['total'] === null ? 'dated_fx' : null,
                    !$arrival ? 'delivery' : (isset($v['required_by']) && $arrival > $v['required_by'] ? 'requested_date' : null),
                    'landed_cost',
                ])),
                'quote' => $quote ? array_diff_key($quote, $money ? [] : array_flip(['unit_price', 'total', 'original_total', 'fx_date', 'fx_rate'])) : null,
                'catalogue_estimate' => $money ? $this->catalogue($mapping, $quantity, $currency, $today->toDateString()) : null,
                'minimum_base_quantity' => $mapping?->minimum_order_quantity, 'pack_size' => $mapping?->pack_size,
                'catalogue_next_valid_base_quantity' => $mapping && $packQuantity > $quantity ? $packQuantity : null,
                'catalogue_lead_days' => $mapping?->usual_lead_time_days, 'quoted_arrival_if_ordered_today' => $arrival,
                'quoted_date_fits' => isset($v['required_by']) ? ($arrival !== null && $arrival <= $v['required_by']) : null,
                'history' => $distribution, 'recent_ordered' => $lines->isEmpty() ? null : $lines->sum('ordered'),
                'recent_accepted' => $lines->isEmpty() ? null : $lines->sum('accepted'),
                'inspections' => $inspections->count(), 'rejected_quantity' => $inspections->isEmpty() ? null : $inspections->sum('rejected_quantity'),
                'orders' => $latest->map(fn ($o) => ['reference' => $o['reference'], 'url' => '/purchase-orders?po='.$o['order_id']])->values()->all(),
            ];
        }
        $priority = $v['priority'] ?? 'balanced';
        $recommendation = $limited ? null : $this->recommend($rows, $priority, $money);
        $preferences = [];
        foreach (['cost', 'speed', 'reliability', 'balanced'] as $criterion) {
            $preferences[$criterion] = $limited ? null : $this->recommend($rows, $criterion, $money);
        }
        // A user may select a supplier or use the qualified recommendation. Never silently assume one.
        $scenarioSupplier = $selected?->id ?? $recommendation['id'] ?? null;
        $scenarios = [];
        foreach (['conservative' => .5, 'balanced' => 1, 'growth' => 1.5] as $key => $factor) {
            $explicit = $v['scenario_quantities'][$key] ?? null;
            $scale = $units->allowsDecimals($normalized['unit']) ? 3 : 0;
            $amount = $explicit ?? max(10 ** -$scale, round((float) $v['quantity'] * $factor, $scale));
            $base = $units->resolve($product, (float) $amount, $normalized['unit'])['base_quantity'];
            $quote = $money && $scenarioSupplier ? $this->quote($quotes->get($scenarioSupplier, collect()), $product, $base, $currency, $today->toDateString()) : null;
            if ($quote && isset($v['required_by']) && ($quote['lead_days'] === null || $today->addDays($quote['lead_days'])->toDateString() > $v['required_by'])) $quote = null;
            $estimate = $money && $scenarioSupplier ? $this->catalogue($catalogue->get($scenarioSupplier), $base, $currency, $today->toDateString()) : null;
            $assumption = isset($v['assumed_unit_price']) ? Money::normalizeDecimal($v['assumed_unit_price'], 4) : null;
            $source = isset($quote['total']) ? 'quote' : ($assumption !== null ? 'assumption' : ($estimate ? 'catalogue' : null));
            $cost = match ($source) {
                'quote' => $quote['total'],
                'assumption' => Money::multiply($assumption, $amount),
                'catalogue' => $estimate['total'],
                default => null,
            };
            $priceEvidence = match ($source) {
                'quote' => ['url' => $quote['url'], 'reference' => $quote['reference'], 'valid_until' => $quote['valid_until']],
                'catalogue' => ['updated_at' => $estimate['updated_at'], 'fx_date' => $estimate['fx_date']],
                'assumption' => ['user_entered' => true],
                default => null,
            };
            $extras = isset($v['assumed_extra_cost']) ? Money::normalize($v['assumed_extra_cost']) : null;
            $scenarios[] = ['id' => $key, 'quantity' => $amount, 'base_quantity' => $base, 'supplier_id' => $scenarioSupplier,
                'material_cost' => $cost, 'cost_source' => $source, 'price_evidence' => $priceEvidence,
                'price_per_purchase_unit' => match ($source) {
                    'quote' => Money::multiply($quote['unit_price'], $normalized['conversion_factor'] ?? 1, 6),
                    'catalogue' => Money::multiply($estimate['unit_price'], $normalized['conversion_factor'] ?? 1, 6),
                    'assumption' => $assumption,
                    default => null,
                },
                'assumed_extra_cost' => $extras,
                'remaining_envelope' => isset($v['budget']) && $cost !== null && $source !== 'assumption' && $extras === null ? Money::subtract($v['budget'], $cost) : null,
                'illustrative_envelope_remaining' => isset($v['budget']) && $cost !== null ? Money::subtract($v['budget'], Money::add($cost, $extras ?? 0)) : null];
        }
        $stock = app(InventorySnapshotService::class)->forProduct($product, null, $warehouse?->id);
        $horizons = [];
        foreach ([30, 60, 90] as $days) {
            $through = $today->addDays($days)->toDateString();
            $horizons[] = ['days' => $days, 'through' => $through,
                'dated_incoming' => round(collect($stock['incoming_schedule'])->filter(fn ($row) => $row['expected_at']
                    && $row['expected_at'] >= $today->toDateString() && $row['expected_at'] <= $through)->sum('quantity'), 3)];
        }
        $financial = $finance && $withFinance ? app(InventoryPlanningInsights::class)->finance($currency) : null;
        if ($financial) {
            $financial['upcoming_po_payments'] = collect($financial['upcoming_po_payments'])->sortBy('due_date')->take(8)->values()->all();
        }

        return [
            'product' => $product->only('id', 'name', 'sku', 'unit'), 'warehouse' => $warehouse?->only('id', 'name'),
            'quantity' => (float) $v['quantity'], 'normalized' => $normalized, 'required_by' => $v['required_by'] ?? null,
            'base_currency' => $currency, 'as_of' => $today->toDateString(), 'priority' => $priority,
            'suppliers' => $rows, 'evidence_limited' => $limited, 'recommendation' => $recommendation,
            'preferences' => $preferences,
            'quote_candidates' => array_values(array_filter($rows, fn ($row) => $row['evidence_type'] !== 'comparable_offer'
                && ($row['history']['samples'] > 0 || $row['orders'] || $row['catalogue_linked'] || $row['product_supplier']))),
            'other_registered_suppliers' => $rows || !$withFinance ? [] : (clone $activeSuppliers)
                ->orderBy('name')->limit(3)->get(['id', 'name'])->toArray(),
            'stock' => array_intersect_key($stock, array_flip(['available', 'reserved', 'incoming', 'incoming_quantity_complete', 'available_to_promise'])),
            'firm_shortfall_now' => round(max(0, $stock['committed_outgoing'] - $stock['available']), 3),
            'horizons' => $horizons,
            'scenarios' => $scenarios, 'budget' => $v['budget'] ?? null, 'financial' => $financial,
            'can_view_cost' => $money, 'can_view_budget' => $finance, 'read_only' => true, 'url' => '/procurement',
        ];
    }

    private function quote($offers, Product $product, float $quantity, string $base, string $today): ?array
    {
        foreach ($offers as $line) {
            $offer = $line->supplierQuote;
            try {
                $offered = app(UnitConversionService::class)->resolve($product, (float) $line->offered_quantity, $line->requestItem->unit);
            } catch (ValidationException) {
                continue;
            }
            if (abs($offered['base_quantity'] - $quantity) > .00001 || $line->offered_quantity < $line->minimum_order_quantity) {
                continue;
            }
            $date = $offer->exchange_rate_date?->toDateString();
            $rate = $this->rate($offer->currency, $base, $offer->exchange_rate, $date, $today);
            $raw = Money::multiply($line->unit_price, $line->offered_quantity);
            return ['reference' => 'RFQ #'.$offer->rfq_id, 'url' => '/procurement?rfq='.$offer->rfq_id,
                'valid_until' => $offer->valid_until->toDateString(), 'offer_currency' => $offer->currency,
                'original_total' => $raw, 'unit_price' => $rate !== null ? Money::multiply($line->unit_price, $rate / ($offered['conversion_factor'] ?? 1), 6) : null,
                'total' => $rate !== null ? Money::multiply($raw, $rate) : null, 'fx_date' => $date, 'fx_rate' => $rate,
                'lead_days' => $line->lead_time_days === null ? null : (int) $line->lead_time_days,
                'shipping_terms' => $offer->shipping_terms];
        }
        return null;
    }

    private function catalogue(?ProductSupplier $mapping, float $quantity, string $base, string $today): ?array
    {
        if (!$mapping || $mapping->purchase_price === null || $quantity < (float) $mapping->minimum_order_quantity) return null;
        $pack = (float) $mapping->pack_size;
        if ($pack > 0 && abs($quantity / $pack - round($quantity / $pack)) > .000001) return null;
        $date = $mapping->exchange_rate_date?->toDateString();
        $rate = $this->rate($mapping->currency, $base, $mapping->exchange_rate_to_base, $date, $today);
        if ($rate === null) return null;
        $unit = Money::multiply($mapping->purchase_price, $rate, 8);
        return ['unit_price' => $unit, 'total' => Money::multiply($unit, $quantity), 'currency' => $mapping->currency,
            'fx_date' => $date, 'updated_at' => $mapping->last_price_changed_at?->toDateString()];
    }

    private function rate(?string $currency, string $base, mixed $rate, ?string $date, string $today): ?float
    {
        if ($currency === $base) return 1.0;
        return $currency && $date && $date <= $today && $rate > 0 ? (float) $rate : null;
    }

    private function recommend(array $rows, string $priority, bool $money): ?array
    {
        $minimum = (int) config('supplier_intelligence.minimum_orders');
        $scored = [];
        foreach ($rows as $row) {
            $cost = $row['quote']['total'] ?? null;
            $lead = $row['quote']['lead_days'] ?? null;
            $reliable = $row['history']['promised_samples'] >= $minimum ? $row['history']['on_time_percent'] : null;
            if ($priority !== 'reliability' && $row['quoted_date_fits'] === false) continue;
            $score = match ($priority) {
                'cost' => $money && $cost !== null ? [Money::minor($cost)] : null,
                'speed' => $lead !== null ? [$lead] : null,
                'reliability' => $reliable !== null ? [-$reliable] : null,
                'balanced' => $money && $cost !== null && $lead !== null && $reliable !== null
                    ? [Money::minor($cost), $lead, -$reliable] : null,
            };
            if ($score !== null) $scored[] = ['id' => $row['id'], 'name' => $row['name'], 'score' => $score];
        }
        if (count($scored) < 2) return null;
        // Balanced chooses only a clear winner: no worse on every metric, better on at least one.
        foreach ($scored as $candidate) {
            foreach ($scored as $other) {
                if ($other['id'] === $candidate['id']) continue;
                if ($candidate['score'] === $other['score']) continue 2;
                foreach ($candidate['score'] as $index => $value) {
                    if ($value > $other['score'][$index]) continue 3;
                }
            }
            $evidence = collect($rows)->firstWhere('id', $candidate['id']);
            return ['id' => $candidate['id'], 'name' => $candidate['name'], 'criterion' => $priority, 'comparable_suppliers' => count($scored),
                'history' => $priority === 'reliability' ? $evidence['history'] : null];
        }
        return null;
    }
}
