<?php
require __DIR__.'/bootstrap.php';
$rows=App\Models\OnboardingRow::withoutGlobalScopes()->where('status','error')->get(['id','domain','external_ref','errors','normalized'])->toArray();
dc16Evidence('opening-row-errors',$rows);
foreach($rows as $row) echo json_encode(array_intersect_key($row,array_flip(['id','domain','external_ref','errors'])))."\n";
