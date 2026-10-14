<?php

require __DIR__.'/bootstrap.php';
require __DIR__.'/evidence.php';
echo json_encode(addPerformanceEvidence($owner->company_id)).PHP_EOL;
