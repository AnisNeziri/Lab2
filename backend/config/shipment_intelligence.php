<?php
return [
    'version'=>'logistics-v6.1',
    'minimum_route_samples'=>5,
    'history_limit'=>250,
    'batch_size'=>5,
    'worker_seconds'=>20,
    'refresh_minutes'=>30,
    'material_eta_days'=>2,
    'position_stale_hours'=>24,
    'prediction_max_age_hours'=>24,
    'arriving_soon_days'=>3,
    // No constant transit duration or artificial customs/inland allowance.
    // Unknown stages remain unknown until genuine history/schedules exist.
];
