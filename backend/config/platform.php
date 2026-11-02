<?php
return ['telemetry_enabled'=>env('AIMS_OBSERVABILITY_ENABLED',false),'slow_query_ms'=>(float)env('AIMS_SLOW_QUERY_MS',100),'span_retention_days'=>7];
