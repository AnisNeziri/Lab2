<?php
return [
 'version'=>'decision-policy-v5.1',
 // Lower risk score wins. Unknown evidence receives a neutral uncertainty
 // penalty, never a fabricated zero risk. Scores are not probabilities.
 'weights'=>['stockout'=>40,'availability'=>20,'delay'=>12,'quality'=>8,'cost'=>10,'excess'=>6,'commitment'=>4],
 'stale_hours'=>24,'supplier_limit'=>5,'batch_limit'=>5,'worker_seconds'=>20,
 'high_commitment'=>10000,'material_quantity_fraction'=>.1,
];
