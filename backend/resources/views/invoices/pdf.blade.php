<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number ?: 'Draft invoice' }}</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; color: #14213d; font-size: 10px; line-height: 1.35; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .header { border-bottom: 3px solid #1574d1; padding-bottom: 12px; margin-bottom: 16px; }
        .title { color: #0a4f96; font-size: 22px; font-weight: 700; }
        .number { text-align: right; font-size: 12px; }
        .muted { color: #60738b; }
        .party { width: 49%; border: 1px solid #d9e5f2; border-radius: 5px; padding: 10px; }
        .party-title { color: #0a4f96; font-size: 11px; font-weight: 700; margin-bottom: 5px; }
        .spacer { width: 2%; }
        .meta { margin: 14px 0; background: #f3f8fd; }
        .meta td { border: 1px solid #d9e5f2; padding: 6px; }
        .items th { color: #fff; background: #0a4f96; padding: 7px 4px; font-size: 8px; }
        .items td { border-bottom: 1px solid #dfe8f2; padding: 7px 4px; }
        .right { text-align: right; }
        .center { text-align: center; }
        .totals { width: 45%; margin-left: 55%; margin-top: 12px; }
        .totals td { padding: 5px 7px; border-bottom: 1px solid #dfe8f2; }
        .grand td { font-size: 13px; font-weight: 700; color: #0a4f96; border-top: 2px solid #0a4f96; }
        .summary { margin-top: 14px; }
        .summary th, .summary td { border: 1px solid #d9e5f2; padding: 5px; }
        .summary th { background: #edf5fc; }
        .info { margin-top: 14px; border: 1px solid #d9e5f2; padding: 9px; }
        .warning { margin-top: 14px; padding: 8px; border: 1px solid #d69e2e; background: #fff8dd; color: #684b00; }
        .watermark { position: fixed; top: 38%; left: 12%; color: #d9e5f2; font-size: 75px; font-weight: 700; transform: rotate(-30deg); z-index: -1; }
        .footer { margin-top: 18px; border-top: 1px solid #d9e5f2; padding-top: 7px; color: #60738b; font-size: 8px; }
    </style>
</head>
<body>
@php
    $seller = $invoice->seller_snapshot ?: [];
    $buyer = $invoice->buyer_snapshot ?: [];
    $isDraft = $invoice->status === 'draft';
    $isLegacy = $invoice->compliance_status === 'legacy';
    $isVoid = $invoice->status === 'void';
    $amountSign = $invoice->document_type === 'credit_note' ? '− ' : '';
@endphp

@if($isDraft)<div class="watermark">{{ $labels['draft'] }}</div>@endif
@if($isVoid)<div class="watermark">VOID / ANULUAR</div>@endif

<table class="header">
    <tr>
        <td>
            <div class="title">{{ $invoice->document_type === 'credit_note' ? $labels['credit_note'] : $labels['invoice'] }}</div>
            <div class="muted">AIMS — Advanced Inventory Management System</div>
        </td>
        <td class="number">
            <strong>{{ $labels['number'] }}</strong><br>
            {{ $invoice->invoice_number ?: $labels['draft'].' #'.$invoice->id }}
        </td>
    </tr>
</table>

<table>
    <tr>
        <td class="party">
            <div class="party-title">{{ $labels['seller'] }}</div>
            <strong>{{ $seller['legal_name'] ?? '—' }}</strong>
            @if(!empty($seller['trade_name']))<br>{{ $seller['trade_name'] }}@endif
            <br>{{ $seller['registered_address'] ?? '—' }}
            <br>{{ implode(' ', array_filter([$seller['postal_code'] ?? null, $seller['municipality'] ?? null, $seller['country_code'] ?? null])) }}
            <br>{{ $labels['business_number'] }}: {{ $seller['business_registration_number'] ?? '—' }}
            <br>{{ $labels['fiscal_number'] }}: {{ $seller['fiscal_number'] ?? '—' }}
            @if(!empty($seller['is_vat_registered']))<br>{{ $labels['vat_number'] }}: {{ $seller['vat_number'] ?? '—' }}@endif
            @if(!empty($seller['phone']))<br>{{ $seller['phone'] }}@endif
            @if(!empty($seller['email']))<br>{{ $seller['email'] }}@endif
        </td>
        <td class="spacer"></td>
        <td class="party">
            <div class="party-title">{{ $labels['buyer'] }}</div>
            <strong>{{ $buyer['legal_name'] ?? $invoice->customer_name ?? '—' }}</strong>
            @if(!empty($buyer['trade_name']))<br>{{ $buyer['trade_name'] }}@endif
            <br>{{ $buyer['address'] ?? '—' }}
            <br>{{ implode(' ', array_filter([$buyer['postal_code'] ?? null, $buyer['municipality'] ?? null, $buyer['country_code'] ?? null])) }}
            <br>{{ $labels['business_number'] }}: {{ $buyer['business_registration_number'] ?? '—' }}
            <br>{{ $labels['fiscal_number'] }}: {{ $buyer['fiscal_number'] ?? '—' }}
            @if(!empty($buyer['is_vat_registered']))<br>{{ $labels['vat_number'] }}: {{ $buyer['vat_number'] ?? '—' }}@endif
            @if(!empty($buyer['phone']))<br>{{ $buyer['phone'] }}@endif
            @if(!empty($buyer['email']))<br>{{ $buyer['email'] }}@endif
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td><strong>{{ $labels['issue_date'] }}</strong><br>{{ $invoice->issued_at?->format('d.m.Y H:i') ?: $invoice->invoice_date?->format('d.m.Y') }}</td>
        <td><strong>{{ $labels['supply_date'] }}</strong><br>{{ $invoice->supply_date?->format('d.m.Y') }} {{ $invoice->supply_time ? substr((string) $invoice->supply_time, 0, 5) : '' }}</td>
        <td><strong>{{ $labels['due_date'] }}</strong><br>{{ $invoice->due_at?->format('d.m.Y') ?: '—' }}</td>
        <td><strong>Currency / Valuta</strong><br>EUR</td>
        <td><strong>Status</strong><br>{{ strtoupper($invoice->payment_status ?: $invoice->status) }}</td>
    </tr>
</table>

@if($invoice->document_type === 'credit_note')
<div class="info">
    <strong>{{ $labels['original'] }}:</strong> {{ $invoice->originalInvoice?->invoice_number ?: '—' }}<br>
    <strong>{{ $labels['reason'] }}:</strong> {{ $invoice->credit_reason }}
</div>
@endif

<table class="items">
    <thead>
        <tr>
            <th style="width:4%">#</th><th style="width:26%">{{ $labels['description'] }}</th><th style="width:8%">{{ $labels['sku'] }}</th>
            <th style="width:6%">{{ $labels['unit'] }}</th><th style="width:7%">{{ $labels['quantity'] }}</th>
            <th style="width:11%">{{ $labels['unit_price'] }}</th><th style="width:9%">{{ $labels['discount'] }}</th>
            <th style="width:10%">{{ $labels['taxable'] }}</th><th style="width:7%">{{ $labels['vat_rate'] }}</th><th style="width:12%">{{ $labels['total'] }}</th>
        </tr>
    </thead>
    <tbody>
    @foreach($invoice->items as $index => $item)
        <tr>
            <td class="center">{{ $index + 1 }}</td>
            <td><strong>{{ $item->description }}</strong>@if($item->tax_treatment !== 'standard')<br><span class="muted">{{ str_replace('_', ' ', strtoupper($item->tax_treatment)) }}</span>@endif</td>
            <td>{{ $item->sku_snapshot ?: '—' }}</td><td class="center">{{ $item->unit }}</td>
            <td class="right">{{ rtrim(rtrim(number_format((float)$item->quantity, 3, '.', ''), '0'), '.') }}</td>
            <td class="right">€{{ number_format((float)$item->unit_price, 2) }}</td>
            <td class="right">€{{ number_format((float)$item->discount_amount, 2) }}</td>
            <td class="right">€{{ number_format((float)$item->taxable_amount, 2) }}</td>
            <td class="right">{{ number_format((float)$item->vat_rate, 0) }}%</td>
            <td class="right"><strong>{{ $amountSign }}€{{ number_format((float)$item->line_total, 2) }}</strong></td>
        </tr>
        @if($item->tax_legal_reference)
        <tr><td></td><td colspan="9" class="muted">{{ $labels['legal_basis'] }}: {{ $item->tax_legal_reference }}</td></tr>
        @endif
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>{{ $labels['subtotal'] }}</td><td class="right">{{ $amountSign }}€{{ number_format((float)$invoice->subtotal, 2) }}</td></tr>
    <tr><td>{{ $labels['discount'] }}</td><td class="right">{{ $invoice->document_type === 'credit_note' ? '' : '− ' }}€{{ number_format((float)$invoice->discount_total, 2) }}</td></tr>
    <tr><td>{{ $labels['taxable_total'] }}</td><td class="right">{{ $amountSign }}€{{ number_format((float)$invoice->taxable_total, 2) }}</td></tr>
    <tr><td>{{ $labels['vat_total'] }}</td><td class="right">{{ $amountSign }}€{{ number_format((float)$invoice->vat_total, 2) }}</td></tr>
    <tr class="grand"><td>{{ $labels['grand_total'] }}</td><td class="right">{{ $amountSign }}€{{ number_format((float)$invoice->grand_total, 2) }}</td></tr>
    <tr><td>{{ $labels['paid'] }}</td><td class="right">€{{ number_format((float)$invoice->total_paid, 2) }}</td></tr>
    <tr><td>{{ $labels['balance'] }}</td><td class="right">€{{ number_format((float)$invoice->remaining_balance, 2) }}</td></tr>
</table>

<table class="summary">
    <thead><tr><th colspan="4">{{ $labels['vat_summary'] }}</th></tr><tr><th>{{ $labels['vat_rate'] }}</th><th>{{ $labels['taxable'] }}</th><th>{{ $labels['vat'] }}</th><th>{{ $labels['legal_basis'] }}</th></tr></thead>
    <tbody>
    @foreach($vatSummary as $row)
        <tr><td>{{ str_replace('_', ' ', strtoupper($row['tax_treatment'])) }} {{ number_format($row['vat_rate'], 0) }}%</td><td class="right">{{ $amountSign }}€{{ number_format($row['taxable_amount'], 2) }}</td><td class="right">{{ $amountSign }}€{{ number_format($row['vat_amount'], 2) }}</td><td>{{ $row['legal_reference'] ?: '—' }}</td></tr>
    @endforeach
    </tbody>
</table>

@if($invoice->payment_terms || !empty($seller['bank_name']) || !empty($seller['iban']))
<div class="info"><strong>{{ $labels['payment'] }}</strong><br>
    @if($invoice->payment_terms){{ $invoice->payment_terms }}<br>@endif
    {{ implode(' · ', array_filter([$seller['bank_name'] ?? null, $seller['bank_account'] ?? null, $seller['iban'] ?? null, $seller['swift_bic'] ?? null])) }}
</div>
@endif
@if($invoice->notes)<div class="info"><strong>{{ $labels['notes'] }}</strong><br>{{ $invoice->notes }}</div>@endif

<div class="warning">
    @if($isDraft)<strong>DRAFT:</strong> Ky dokument nuk është lëshuar / This document has not been issued.<br>@endif
    @if($isVoid)<strong>VOID / ANULUAR:</strong> Ky draft është anuluar dhe nuk është faturë e lëshuar / This draft was voided and is not an issued invoice.<br>@endif
    @if($isLegacy)<strong>LEGACY:</strong> Të dhënat e vjetra nuk janë verifikuar si faturë tatimore e plotë / Legacy data has not been verified as a complete tax invoice.<br>@endif
    Kjo është faturë tatimore B2B, jo kupon fiskal i ATK-së. AIMS nuk ka gjeneruar apo verifikuar kod fiskal ose QR zyrtar.<br>
    This is a B2B tax invoice, not a TAK fiscal receipt. AIMS has not generated or verified an official fiscal code or QR.
    @if($invoice->fiscal_receipt_number || $invoice->external_fiscal_code)<br>Referencat fiskale më poshtë janë futur manualisht dhe nuk janë verifikuar nga AIMS / Fiscal references below were entered manually and are not verified by AIMS.@endif
</div>

<div class="footer">
    Issued by / Lëshuar nga: {{ $invoice->issuer?->name ?: ($invoice->creator?->name ?: '—') }} ·
    @if($invoice->fiscal_receipt_number)Fiscal receipt reference: {{ $invoice->fiscal_receipt_number }} · @endif
    @if($invoice->external_fiscal_code)External fiscal code: {{ $invoice->external_fiscal_code }} · @endif
    @if($invoice->integrity_hash)AIMS internal document checksum (not a digital signature): {{ $invoice->integrity_hash }} · @endif
    Retain until / Ruaj deri më: {{ $invoice->retention_until?->format('d.m.Y') ?: '—' }}
</div>
</body>
</html>
