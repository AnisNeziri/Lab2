<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $labels['title'] }} {{ $sale->sale_number }}</title>
    <style>
        @page { margin: 18mm 14mm; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            color: #111;
            font-size: 12px;
            line-height: 1.4;
            margin: 0;
        }
        .sheet { width: 100%; }
        .header {
            border: 2px solid #111;
            padding: 14px 16px;
            margin-bottom: 14px;
        }
        .header-top {
            width: 100%;
            border-collapse: collapse;
        }
        .header-top td { vertical-align: top; }
        .company-name {
            font-size: 20px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .company-address {
            font-size: 11px;
            margin-top: 4px;
        }
        .logo-box {
            width: 72px;
            height: 72px;
            border: 1px solid #111;
            text-align: center;
            font-size: 10px;
            font-weight: bold;
            line-height: 72px;
        }
        .meta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .meta td {
            border: 1px solid #111;
            padding: 8px 10px;
        }
        .meta-label {
            font-weight: bold;
            width: 28%;
            background: #f5f5f5;
        }
        .items {
            width: 100%;
            border-collapse: collapse;
        }
        .items th,
        .items td {
            border: 1px solid #111;
            padding: 7px 8px;
        }
        .items th {
            background: #f0f0f0;
            font-size: 11px;
            text-align: left;
        }
        .items .num { width: 6%; text-align: center; }
        .items .unit { width: 10%; }
        .items .qty { width: 10%; text-align: right; }
        .items .price { width: 12%; text-align: right; }
        .items .amount { width: 12%; text-align: right; }
        .footer {
            margin-top: 14px;
            width: 100%;
            border-collapse: collapse;
        }
        .footer td {
            border: 1px solid #111;
            padding: 10px;
            vertical-align: top;
        }
        .total-row {
            font-size: 14px;
            font-weight: bold;
            text-align: right;
        }
        .signature-line {
            margin-top: 36px;
            border-top: 1px solid #111;
            padding-top: 6px;
            min-height: 28px;
        }
        .status-badge {
            display: inline-block;
            border: 1px solid #111;
            padding: 2px 8px;
            font-size: 10px;
            font-weight: bold;
            margin-top: 6px;
        }
        .text-right { text-align: right; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="header">
        <table class="header-top">
            <tr>
                <td style="width: 75%;">
                    <div class="company-name">{{ $company->name ?? 'AIMS' }}</div>
                    @if(!empty($company->address))
                        <div class="company-address">{{ $company->address }}</div>
                    @endif
                    <div class="status-badge">{{ $sale->status === 'finalized' ? $labels['finalized'] : $labels['draft'] }}</div>
                </td>
                <td style="width: 25%; text-align: right;">
                    <div class="logo-box">AIMS</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="meta">
        <tr>
            <td class="meta-label">{{ $labels['title'] }}</td>
            <td colspan="3" style="font-size: 16px; font-weight: bold;">{{ $labels['title'] }}</td>
        </tr>
        <tr>
            <td class="meta-label">{{ $labels['date'] }}</td>
            <td>{{ $sale->sale_date?->format('d/m/Y') }}</td>
            <td class="meta-label">{{ $labels['number'] }}</td>
            <td>{{ $sale->sale_number }}</td>
        </tr>
        <tr>
            <td class="meta-label">{{ $labels['customer'] }}</td>
            <td colspan="3">{{ $sale->customer_name ?: '—' }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th class="num">{{ $labels['no'] }}</th>
                <th>{{ $labels['product'] }}</th>
                <th class="unit">{{ $labels['unit'] }}</th>
                <th class="qty">{{ $labels['quantity'] }}</th>
                <th class="price">{{ $labels['price'] }}</th>
                <th class="amount">{{ $labels['amount'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->items as $item)
                <tr>
                    <td class="num">{{ $item->line_number }}</td>
                    <td>{{ $item->product_name }}</td>
                    <td class="unit">{{ $item->unit }}</td>
                    <td class="qty">{{ $item->quantity }}</td>
                    <td class="price">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="amount">{{ number_format($item->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="footer">
        <tr>
            <td style="width: 65%;">
                <strong>{{ $labels['notes'] }}</strong>
                <div style="min-height: 48px; margin-top: 6px;">{{ $sale->notes ?: ' ' }}</div>
            </td>
            <td style="width: 35%;">
                <div class="total-row">{{ $labels['total'] }}: {{ number_format($sale->total_amount, 2) }}</div>
                <div class="signature-line">
                    <strong>{{ $labels['signature'] }}</strong><br>
                    {{ $sale->signature_name ?: ' ' }}
                </div>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
