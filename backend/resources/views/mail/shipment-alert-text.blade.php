AIMS Shipment Alert
===================

{{ $eventLabel }}

Tracking number: {{ $shipment->tracking_number ?: $shipment->tracking_reference }}
Status: {{ str_replace('_', ' ', ucfirst($shipment->status)) }}
Current location: {{ $shipment->last_location_label ?: '—' }}
ETA: {{ optional($shipment->eta)->format('M j, Y H:i') ?: '—' }}
Destination: {{ $shipment->destination_port }}

— AIMS
