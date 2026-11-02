<?php

namespace App\Services\Platform;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmploymentTerm;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Services\DocumentService;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Auth;

class SearchEntityRegistry
{
    public const TYPES = [
        'product' => [Product::class, 'inventory.view', 'name', 'sku', '/products?product='],
        'customer' => [Customer::class, 'debts.view', 'name', 'business_registration_number', '/customer-debts?customer='],
        'supplier' => [Supplier::class, 'supplier_catalogue.view', 'name', 'name', '/suppliers?supplier='],
        'order' => [SalesOrder::class, 'fulfillment.view', 'order_number', 'order_number', '/fulfillment?order='],
        'purchase_order' => [PurchaseOrder::class, 'purchase_orders.view', 'po_number', 'po_number', '/purchase-orders?po='],
        'shipment' => [Shipment::class, 'shipments.view', 'tracking_number', 'tracking_number', '/shipments/my-shipments?shipment='],
        'employee' => [Employee::class, 'employees.view', 'name', 'employee_number', '/workforce?employee='],
        'employment_term' => [EmploymentTerm::class, 'employees.view', 'effective_to', 'id', '/workforce?term='],
        'document' => [Document::class, 'documents.view', 'title', 'reference', '/documents?document='],
    ];

    public function allowed(string $type): bool
    {
        return isset(self::TYPES[$type]) && app(PermissionService::class)->roleHasPermission(Auth::user()->role, self::TYPES[$type][1]);
    }

    public function query(string $type)
    {
        return $type === 'document' ? app(DocumentService::class)->visibleQuery()->whereNull('archived_at') : self::TYPES[$type][0]::query()->where('company_id', Auth::user()->company_id);
    }

    public function normalize(string $type, $row): array
    {
        [,,$title,$identifier,$url] = self::TYPES[$type];
        $parts = [];
        foreach (match ($type) {
            'product' => ['name', 'sku', 'barcode', 'description'],'customer' => ['name', 'municipality', 'business_registration_number'],'supplier' => ['name', 'country', 'city'],'shipment' => ['tracking_number', 'origin_port', 'destination_port', 'status', 'eta', 'commercial_invoice_number'],'document' => ['title', 'reference', 'issuer', 'tags'],'employee' => ['name', 'employee_number', 'department', 'position'],'employment_term' => ['effective_to', 'effective_from'],default => [$title, 'status']
        } as $field) {
            $v = $row->$field;
            if ($v !== null) {
                $parts[] = is_array($v) ? implode(' ', array_filter($v, 'is_scalar')) : (string) $v;
            }
        }
        if ($type === 'document') {
            $parts[] = ($row->type?->company_id === $row->company_id) ? ($row->type?->name ?? '') : '';
        }
        if ($type === 'product') {
            $parts[] = ($row->category?->company_id === $row->company_id) ? ($row->category?->name ?? '') : '';
        }
        if ($type === 'employment_term') {
            $parts[] = 'employee contract ending '.(($row->employee?->company_id === $row->company_id) ? $row->employee?->name : '');
        }

        // No raw journal rows, compensation, private files, bank details or extracted file contents.
        return ['title' => mb_substr((string) ($row->$title ?: ucfirst($type).' #'.$row->id), 0, 255), 'identifier' => mb_substr((string) ($row->$identifier ?? ''), 0, 255), 'text' => mb_substr(implode(' ', $parts), 0, 3000), 'url' => $url.$row->id];
    }
}
