<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Services\ShipmentLogisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ShipmentLogisticsController extends Controller
{
    public function __construct(private readonly ShipmentLogisticsService $logistics) {}

    public function update(Request $request, Shipment $shipment): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $validated = $request->validate([
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')->where('company_id', $companyId)],
            'purchase_order_ids' => ['sometimes', 'array', 'max:100'],
            'purchase_order_ids.*' => ['integer', Rule::exists('purchase_orders', 'id')->where('company_id', $companyId)],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'bill_of_lading' => ['nullable', 'string', 'max:100'],
            'commercial_invoice_number' => ['nullable', 'string', 'max:100'],
            'incoterm' => ['nullable', Rule::in(['EXW', 'FCA', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP', 'FAS', 'FOB', 'CFR', 'CIF'])],
            'transshipment_port' => ['nullable', 'string', 'max:255'],
            'arrival_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'containers' => ['sometimes', 'array', 'max:100'],
            'containers.*.id' => ['nullable', 'integer'],
            'containers.*.client_key' => ['nullable', 'string', 'max:80'],
            'containers.*.container_number' => ['required', 'string', 'max:30'],
            'containers.*.seal_number' => ['nullable', 'string', 'max:50'],
            'containers.*.container_type' => ['nullable', 'string', 'max:50'],
            'containers.*.booking_reference' => ['nullable', 'string', 'max:100'],
            'containers.*.bill_of_lading' => ['nullable', 'string', 'max:100'],
            'containers.*.forwarder' => ['nullable', 'string', 'max:160'],
            'containers.*.vessel_name' => ['nullable', 'string', 'max:160'],
            'containers.*.voyage' => ['nullable', 'string', 'max:100'],
            'containers.*.origin_port' => ['nullable', 'string', 'max:160'],
            'containers.*.destination_port' => ['nullable', 'string', 'max:160'],
            'containers.*.etd' => ['nullable', 'date'],
            'containers.*.eta' => ['nullable', 'date'],
            'containers.*.actual_departure' => ['nullable', 'date'],
            'containers.*.actual_arrival' => ['nullable', 'date'],
            'containers.*.status' => ['nullable', Rule::in(['planned', 'booked', 'loading', 'loaded', 'departed', 'in_transit', 'arrived', 'customs', 'customs_cleared', 'delivered'])],
            'containers.*.capacity_cbm' => ['nullable', 'numeric', 'min:0'],
            'containers.*.capacity_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'containers.*.gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'containers.*.volume_m3' => ['nullable', 'numeric', 'min:0'],
            'containers.*.notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.shipment_container_id' => ['nullable', 'integer'],
            'items.*.shipment_container_key' => ['nullable', 'string', 'max:80'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.planned_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'items.*.loaded_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.base_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'items.*.unit_cbm' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_weight_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($this->logistics->update($shipment, $validated));
    }

    public function uploadDocument(Request $request, Shipment $shipment): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => ['required', Rule::in(['commercial_invoice', 'packing_list', 'bill_of_lading', 'certificate_of_origin', 'customs_declaration', 'transport_invoice', 'insurance', 'payment_confirmation', 'other'])],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,xlsx,xls,csv,doc,docx', 'max:10240'],
        ]);

        return response()->json($this->logistics->addDocument($shipment, $validated['document_type'], $validated['document'])->load('uploader:id,name'), 201);
    }

    public function downloadDocument(Shipment $shipment, ShipmentDocument $document): Response
    {
        if ((int) $document->shipment_id !== (int) $shipment->id) {
            abort(404);
        }
        $contents = base64_decode((string) $document->file_data, true);
        abort_if($contents === false || ! hash_equals($document->sha256, hash('sha256', $contents)), 409, 'The stored document failed its integrity check.');

        return response($contents, 200, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $document->filename).'"',
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function deleteDocument(Shipment $shipment, ShipmentDocument $document): JsonResponse
    {
        $this->logistics->deleteDocument($shipment, $document);

        return response()->json(null, 204);
    }
}
