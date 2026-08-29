<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 11px; }
        h1 { margin: 0; color: #0f5ea8; font-size: 22px; }
        .top { display: table; width: 100%; margin-bottom: 22px; }
        .top > div { display: table-cell; width: 50%; vertical-align: top; }
        .right { text-align: right; }
        .meta { background: #f3f7fb; border: 1px solid #d9e4ef; padding: 10px; margin-bottom: 16px; }
        .meta span { display: inline-block; min-width: 180px; margin: 3px 0; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #0f5ea8; color: white; padding: 7px 5px; text-align: left; }
        td { border-bottom: 1px solid #d9e4ef; padding: 7px 5px; vertical-align: top; }
        .number { text-align: right; }
        .muted { color: #607086; }
        .footer { margin-top: 28px; border-top: 1px solid #d9e4ef; padding-top: 8px; color: #607086; }
    </style>
</head>
<body>
<div class="top">
    <div><h1>Goods Receipt</h1><div class="muted">Pranimi i mallrave</div></div>
    <div class="right"><strong>{{ $receipt->receipt_number }}</strong><br>{{ $receipt->received_at?->format('d.m.Y H:i') }}</div>
</div>
<div class="meta">
    <span><strong>Company:</strong> {{ $receipt->company?->name }}</span>
    <span><strong>Purchase order:</strong> {{ $receipt->purchaseOrder?->po_number }}</span><br>
    <span><strong>Supplier:</strong> {{ $receipt->purchaseOrder?->supplier?->name }}</span>
    <span><strong>Warehouse:</strong> {{ $receipt->warehouse?->name }}</span><br>
    <span><strong>Location:</strong> {{ $receipt->location?->path ?? '—' }}</span>
    <span><strong>Supplier document:</strong> {{ $receipt->supplier_document_number ?? '—' }}</span><br>
    <span><strong>Received by:</strong> {{ $receipt->receiver?->name ?? 'System' }}</span>
</div>
<table>
    <thead><tr><th>Product</th><th>Ordered unit</th><th class="number">Accepted</th><th class="number">Damaged</th><th class="number">Rejected</th><th class="number">Inventory added</th></tr></thead>
    <tbody>
    @foreach ($receipt->items as $item)
        <tr>
            <td><strong>{{ $item->product?->name ?? $item->purchaseOrderItem?->description }}</strong><br><span class="muted">{{ $item->product?->sku }}</span></td>
            <td>{{ $item->ordered_unit }}</td>
            <td class="number">{{ number_format((float) $item->accepted_quantity, 3) }}</td>
            <td class="number">{{ number_format((float) $item->damaged_quantity, 3) }}</td>
            <td class="number">{{ number_format((float) $item->rejected_quantity, 3) }}</td>
            <td class="number">{{ number_format((float) $item->accepted_base_quantity + (float) $item->damaged_base_quantity, 3) }} {{ $item->inventory_unit }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@if ($receipt->notes)<p><strong>Notes:</strong> {{ $receipt->notes }}</p>@endif
<div class="footer">This document records the physical receipt and resulting inventory movements. It is not a supplier tax invoice.</div>
</body>
</html>
