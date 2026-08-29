@extends('mail.layout')

@section('content')
<h1 style="margin:0 0 16px;font-size:22px;color:#0f172a;">Shipment Alert</h1>
<p style="margin:0 0 20px;color:#475569;">{{ $eventLabel }} for tracking number <strong>{{ $shipment->tracking_number ?: $shipment->tracking_reference }}</strong>.</p>

<table style="width:100%;border-collapse:collapse;margin-bottom:24px;">
    <tr>
        <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;color:#64748b;width:140px;">Status</td>
        <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;color:#0f172a;">{{ str_replace('_', ' ', ucfirst($shipment->status)) }}</td>
    </tr>
    <tr>
        <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;color:#64748b;">Current location</td>
        <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;color:#0f172a;">{{ $shipment->last_location_label ?: '—' }}</td>
    </tr>
    <tr>
        <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;color:#64748b;">ETA</td>
        <td style="padding:10px 0;border-bottom:1px solid #e2e8f0;color:#0f172a;">{{ optional($shipment->eta)->format('M j, Y H:i') ?: '—' }}</td>
    </tr>
    <tr>
        <td style="padding:10px 0;color:#64748b;">Destination</td>
        <td style="padding:10px 0;color:#0f172a;">{{ $shipment->destination_port }}</td>
    </tr>
</table>

<p style="margin:0;color:#64748b;font-size:14px;">Sign in to AIMS for live map tracking and shipment history.</p>
@endsection
