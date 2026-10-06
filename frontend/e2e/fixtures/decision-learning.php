<?php
// Reuse the isolated V9 business fixture. Its verify branch exits without writes.
ob_start();require __DIR__.'/intelligence-assistant.php';$seed=json_decode(ob_get_clean(),true);
app(\App\Services\DecisionLearningService::class)->maintain();echo json_encode($seed);
