<?php

return [
    'matching' => [
        'quantity_tolerance' => (float) env('SUPPLIER_MATCH_QUANTITY_TOLERANCE', 0.001),
        'unit_price_tolerance' => (float) env('SUPPLIER_MATCH_PRICE_TOLERANCE', 0.02),
        'tax_tolerance' => (float) env('SUPPLIER_MATCH_TAX_TOLERANCE', 0.02),
    ],
];
