<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#172b42}h1{font-size:24px}table{border-collapse:collapse;width:100%;margin-top:20px}td,th{text-align:left;padding:10px;border-bottom:1px solid #ccd5df}.muted{color:#526274}</style></head><body>
<h1>{{ $company->name }}</h1><p>{{ $company->address }}</p><h2>Packing slip / Fletëpaketimi</h2>
<p>{{ $order['order_number'] }} · {{ now()->toDateString() }}</p><p>{{ $order['customer']['name'] }}</p><p class="muted">Operational document / Dokument operacional</p>
<table><thead><tr><th>Product / Produkti</th><th>SKU</th><th>Packed / Paketuar</th><th>Unit / Njësia</th></tr></thead><tbody>@foreach($order['items'] as $item)<tr><td>{{ $item['product']['name'] }}</td><td>{{ $item['product']['sku'] }}</td><td>{{ $item['packed_quantity'] }}</td><td>{{ $item['product']['unit'] }}</td></tr>@endforeach</tbody></table>
<h3>Packages / Pakot</h3>@foreach($order['packages'] as $package)<p>{{ $package['reference'] }} · {{ $package['type'] }} · {{ $package['weight'] }} {{ $package['dimensions'] }}</p>@endforeach
</body></html>
