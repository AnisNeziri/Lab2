<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
            font-size: 14px;
            line-height: 1.5;
            margin: 0;
            padding: 0;
        }
        .invoice-box {
            max-width: 800px;
            margin: auto;
            padding: 30px;
        }
        table {
            width: 100%;
            line-height: inherit;
            text-align: left;
            border-collapse: collapse;
        }
        table td {
            padding: 8px;
            vertical-align: top;
        }
        .header-table td {
            padding: 0;
            padding-bottom: 20px;
        }
        .title {
            font-size: 28px;
            color: #1e3a8a; /* Navy blue */
            font-weight: bold;
        }
        .company-details, .customer-details {
            font-size: 13px;
        }
        .company-details {
            text-align: right;
        }
        .invoice-details-table {
            margin-top: 20px;
            margin-bottom: 30px;
            border-bottom: 2px solid #e5e7eb;
        }
        .invoice-details-table td {
            padding: 5px 0;
            font-size: 13px;
        }
        .invoice-details-label {
            font-weight: bold;
            color: #4b5563;
        }
        .items-table {
            margin-top: 20px;
        }
        .items-table th {
            background-color: #f3f4f6;
            color: #374151;
            font-weight: bold;
            text-align: left;
            padding: 10px;
            font-size: 13px;
            border-bottom: 2px solid #e5e7eb;
        }
        .items-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 13px;
        }
        .text-right {
            text-align: right;
        }
        .total-section {
            margin-top: 30px;
            float: right;
            width: 300px;
        }
        .total-table td {
            padding: 8px 10px;
            font-size: 14px;
        }
        .total-row {
            font-weight: bold;
            font-size: 16px;
            color: #1e3a8a;
            border-top: 2px solid #1e3a8a;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-paid {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-unpaid {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .status-draft {
            background-color: #f3f4f6;
            color: #374151;
        }
        .status-sent {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .status-overdue {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .footer {
            margin-top: 80px;
            text-align: center;
            font-size: 11px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 20px;
        }
    </style>
</head>
<body>
    <div class="invoice-box">
        <table class="header-table">
            <tr>
                <td>
                    <span class="title">INVENTORY SYSTEM</span><br>
                    <span style="color: #6b7280; font-size: 12px;">Enterprise Management Portal</span>
                </td>
                <td class="company-details">
                    <strong>Enterprise Solutions LLC</strong><br>
                    123 Business Rd, Suite 100<br>
                    Prishtine, 10000, Kosove<br>
                    billing@enterprise.com
                </td>
            </tr>
        </table>

        <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 0;">

        <table class="invoice-details-table">
            <tr>
                <td style="width: 50%;">
                    <h3 style="margin-top: 10px; margin-bottom: 5px; color: #1e3a8a;">Billed To:</h3>
                    <strong>{{ $invoice->customer_name }}</strong><br>
                    Customer Reference: #{{ str_pad($invoice->id, 5, '0', STR_PAD_LEFT) }}
                </td>
                <td style="width: 50%; text-align: right; padding-top: 10px;">
                    <table>
                        <tr>
                            <td class="invoice-details-label text-right">Invoice No:</td>
                            <td class="text-right" style="font-weight: bold;">{{ $invoice->invoice_number }}</td>
                        </tr>
                        <tr>
                            <td class="invoice-details-label text-right">Date Issued:</td>
                            <td class="text-right">{{ $invoice->issued_at->format('M d, Y') }}</td>
                        </tr>
                        @if($invoice->due_at)
                        <tr>
                            <td class="invoice-details-label text-right">Due Date:</td>
                            <td class="text-right">{{ $invoice->due_at->format('M d, Y') }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td class="invoice-details-label text-right">Status:</td>
                            <td class="text-right">
                                <span class="status-badge status-{{ $invoice->status }}">
                                    {{ $invoice->status }}
                                </span>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 50%;">Item / Product Description</th>
                    <th class="text-right" style="width: 15%;">Unit Price</th>
                    <th class="text-right" style="width: 15%;">Quantity</th>
                    <th class="text-right" style="width: 20%;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $item)
                <tr>
                    <td>
                        <strong>{{ $item->product?->name ?? 'Deleted Product' }}</strong><br>
                        <span style="font-size: 11px; color: #6b7280;">{{ $item->description }}</span>
                    </td>
                    <td class="text-right">${{ number_format($item->unit_price, 2) }}</td>
                    <td class="text-right">{{ $item->quantity }}</td>
                    <td class="text-right">${{ number_format($item->line_total, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="total-section">
            <table class="total-table">
                <tr>
                    <td>Subtotal:</td>
                    <td class="text-right">${{ number_format($invoice->total_amount, 2) }}</td>
                </tr>
                <tr>
                    <td>Tax (0%):</td>
                    <td class="text-right">$0.00</td>
                </tr>
                <tr class="total-row">
                    <td>Total Due:</td>
                    <td class="text-right">${{ number_format($invoice->total_amount, 2) }}</td>
                </tr>
            </table>
        </div>

        <div style="clear: both;"></div>

        <div class="footer">
            Thank you for your business!<br>
            If you have any questions about this invoice, please contact support@enterprise.com.<br>
            <span style="font-size: 9px; margin-top: 10px; display: block;">Generated automatically by Enterprise Inventory System</span>
        </div>
    </div>
</body>
</html>
