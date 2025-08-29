<?php

return [
    'api_version'   => 'v1',
    'route_marker'  => '// [crud-generator] tambahkan-di-bawah',
    'cache_ttl'     => 60, // detik
    'sensitive'     => ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'api_token'],
    'export'        => [
        'pdf_view' => 'exports.table', // blade view yang akan dibuat
    ],
    'monitoring'    => [
        'telescope' => env('TELESCOPE_ENABLED', false),
    ],
];
