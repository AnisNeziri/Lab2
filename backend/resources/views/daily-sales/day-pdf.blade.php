<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $labels['title'] }} {{ $date }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #111; font-size: 10px; margin: 0; }
        .header { border-bottom: 2px solid #111; margin-bottom: 12px; padding-bottom: 9px; }
        .header table { width: 100%; }
        .company { font-size: 18px; font-weight: bold; text-transform: uppercase; }
        .title { font-size: 14px; font-weight: bold; margin-top: 3px; }
        .date { text-align: right; font-size: 12px; font-weight: bold; }
        .sale { margin-bottom: 12px; page-break-inside: avoid; }
        .sale-heading { background: #e5e7eb; border: 1px solid #111; padding: 6px 8px; font-weight: bold; }
        .sale-heading .total { float: right; }
        table.items { width: 100%; border-collapse: collapse; }
        .items th, .items td { border: 1px solid #111; padding: 5px 6px; }
        .items th { background: #f5f5f5; text-align: left; }
        .num { width: 5%; text-align: center; }
        .qty, .price, .amount { text-align: right; white-space: nowrap; }
        .sale-notes { border: 1px solid #111; border-top: 0; padding: 5px 6px; }
        .day-total { border: 2px solid #111; padding: 9px 12px; text-align: right; font-size: 15px; font-weight: bold; }
        .empty { border: 1px solid #111; padding: 18px; text-align: center; }
    </style>
</head>
<body>
<div class="header">
    <table><tr>
        <td><div class="company">{{ $company->name ?? 'AIMS' }}</div><div class="title">{{ $labels['title'] }}</div></td>
        <td class="date">{{ $labels['date'] }}: {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</td>
    </tr></table>
</div>

@forelse($sales as $sale)
    <div class="sale">
        <div class="sale-heading">
            {{ $labels['customer'] }} #{{ $loop->iteration }} — {{ $sale->sale_number }}
            <span class="total">{{ $labels['total'] }}: {{ number_format($sale->total_amount, 2) }}</span>
        </div>
        <table class="items">
            <thead><tr>
                <th class="num">{{ $labels['no'] }}</th><th>{{ $labels['product'] }}</th><th>{{ $labels['unit'] }}</th>
                <th class="qty">{{ $labels['quantity'] }}</th><th class="price">{{ $labels['price'] }}</th><th class="amount">{{ $labels['amount'] }}</th>
            </tr></thead>
            <tbody>
            @foreach($sale->items as $item)
                <tr><td class="num">{{ $loop->iteration }}</td><td>{{ $item->product_name }}</td><td>{{ $item->unit }}</td><td class="qty">{{ $item->quantity }}</td><td class="price">{{ number_format($item->unit_price, 2) }}</td><td class="amount">{{ number_format($item->line_total, 2) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        @if($sale->notes)<div class="sale-notes"><strong>{{ $labels['notes'] }}:</strong> {{ $sale->notes }}</div>@endif
    </div>
@empty
    <div class="empty">—</div>
@endforelse

<div class="day-total">{{ $labels['day_total'] }}: {{ number_format($sales->sum('total_amount'), 2) }}</div>
</body>
</html>
