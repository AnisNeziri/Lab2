<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Shipment;
use App\Services\NotificationService;
use App\Services\Tracking\MyShipmentTrackingService;
use App\Services\Tracking\ShipmentAlertService;
use App\Services\Tracking\VesselLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ShipmentController extends Controller
{
    public function __construct(
        private readonly MyShipmentTrackingService $tracking,
        private readonly ShipmentAlertService $alerts,
        private readonly NotificationService $notifications,
        private readonly VesselLookupService $vesselLookup,
    ) {}

    public function ports(): JsonResponse
    {
        return response()->json(config('ports'));
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'archived' => ['sometimes', 'boolean'],
            'saved' => ['sometimes', 'boolean'],
            'favorite' => ['sometimes', 'boolean'],
        ]);

        $query = Shipment::with(['purchaseOrder:id,po_number', 'warehouse:id,name,code', 'supplier:id,name'])
            ->withCount(['containers', 'items', 'documents'])
            ->withSum('items as incoming_quantity', 'base_quantity')
            ->where(function ($verified) {
                $verified->where(function ($provider) {
                    $provider->whereNotNull('tracking_provider')
                        ->whereNotIn('tracking_provider', ['manual', 'demo', 'disabled']);
                })->orWhere(function ($legacyMode) {
                    $legacyMode->whereNull('tracking_provider')
                        ->whereNotNull('tracking_mode')
                        ->whereNotIn('tracking_mode', ['manual', 'demo', 'disabled']);
                });
            })
            ->latest();

        if ($request->has('archived')) {
            $request->boolean('archived') ? $query->archived() : $query->active();
        } else {
            $query->active();
        }

        if ($request->boolean('saved')) {
            $query->saved();
        }

        if ($request->boolean('favorite')) {
            $query->favorites();
        }

        return response()->json($query->get());
    }

    public function validateTracking(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tracking_number' => ['required', 'string', 'max:100'],
            'transport_mode' => ['nullable', 'in:sea,air,cargo'],
        ]);

        return response()->json(
            $this->tracking->validateTrackingNumber(
                $validated['tracking_number'],
                $validated['transport_mode'] ?? null
            )
        );
    }

    public function track(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        $validated = $request->validate([
            'tracking_number' => ['required', 'string', 'max:100'],
            'transport_mode' => ['nullable', 'in:sea,air,cargo'],
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
        ]);

        try {
            $shipment = $this->tracking->trackByNumber($validated, $companyId);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($shipment, 201);
    }

    public function storeAis(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate([
            'lookup_token' => ['required', 'uuid'],
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')->where('company_id', $companyId)],
        ]);

        try {
            $shipment = $this->tracking->createAis($validated, $companyId);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($shipment, 201);
    }

    public function lookupVessel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:30'],
        ]);

        try {
            return response()->json(
                $this->vesselLookup->lookup($validated['identifier'], (int) $request->user()->company_id)
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function show(Shipment $shipment): JsonResponse
    {
        return response()->json($shipment->load([
            'purchaseOrder.supplier', 'purchaseOrder.items.product:id,name,sku,unit,weight_kg,volume_m3',
            'purchaseOrders.supplier', 'purchaseOrders.items.product:id,name,sku,unit,weight_kg,volume_m3',
            'warehouse', 'supplier', 'histories', 'containers.purchaseOrders',
            'containers.items.product:id,name,sku,unit,weight_kg,volume_m3', 'items.product:id,name,sku,unit,weight_kg,volume_m3',
            'items.purchaseOrderItem:id,purchase_order_id,product_id,description,unit,quantity,received_quantity',
            'documents' => fn ($query) => $query->with('uploader:id,name')->latest(),
        ]));
    }

    public function refresh(Shipment $shipment): JsonResponse
    {
        if (config('system.operation_mode') === 'offline') {
            return response()->json([
                'message' => 'Offline mode is active. Showing the last known shipment data.',
                'shipment' => $shipment,
                'offline' => true,
            ]);
        }

        if ($shipment->last_refreshed_at?->gt(now()->subMinute())) {
            return response()->json([
                'message' => 'Tracking was updated recently. Please wait before refreshing again.',
            ], 429);
        }

        try {
            $shipment = $this->tracking->refresh($shipment);
            $shipment->forceFill(['last_refreshed_at' => now()])->save();
        } catch (\Throwable $exception) {
            Log::warning('Shipment tracking refresh failed.', [
                'shipment_id' => $shipment->id,
                'company_id' => Auth::user()->company_id,
                'exception' => $exception,
            ]);

            return response()->json([
                'message' => 'Tracking is currently unavailable. Please try again later.',
            ], 503);
        }

        return response()->json($shipment);
    }

    public function save(Shipment $shipment): JsonResponse
    {
        $shipment->is_saved = true;
        $shipment->save();
        $this->alerts->logHistory($shipment, 'saved', 'Shipment saved to tracked list.');

        return response()->json($shipment);
    }

    public function favorite(Shipment $shipment): JsonResponse
    {
        $shipment->is_favorite = ! $shipment->is_favorite;
        $shipment->save();
        $this->alerts->logHistory(
            $shipment,
            $shipment->is_favorite ? 'favorited' : 'unfavorited',
            $shipment->is_favorite ? 'Shipment marked as favorite.' : 'Shipment removed from favorites.'
        );

        return response()->json($shipment);
    }

    public function archive(Shipment $shipment): JsonResponse
    {
        $shipment->archived_at = now();
        $shipment->save();
        $this->alerts->logHistory($shipment, 'archived', 'Shipment archived.');

        return response()->json($shipment);
    }

    public function restore(Shipment $shipment): JsonResponse
    {
        $shipment->archived_at = null;
        $shipment->notification_state = [];
        $shipment->save();
        $this->alerts->logHistory($shipment, 'restored', 'Shipment restored from archive.');

        return response()->json($shipment);
    }

    public function destroy(Shipment $shipment): JsonResponse
    {
        $this->notifications->clearForShipment((int) $shipment->company_id, (int) $shipment->id);
        if (! $shipment->archived_at) {
            $shipment->forceFill(['archived_at' => now(), 'is_favorite' => false])->save();
            $this->alerts->logHistory($shipment, 'archived_removed', 'Shipment removed from the active tracking list and archived with its history.');
        }

        return response()->json(['message' => 'Shipment archived.', 'shipment' => $shipment->fresh()]);
    }

    public function history(Shipment $shipment): JsonResponse
    {
        return response()->json($shipment->histories()->get());
    }

    public function alerts(): JsonResponse
    {
        $notifications = Notification::where('company_id', Auth::user()->company_id)
            ->whereIn('type', NotificationService::SHIPMENT_TYPES)
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['alerts' => $notifications]);
    }

    public function clearAlerts(): JsonResponse
    {
        $user = Auth::user();
        $count = $this->notifications->clearForUser(
            $user->company_id,
            $user->id,
            'shipments',
        );

        return response()->json(['cleared' => $count]);
    }
}
