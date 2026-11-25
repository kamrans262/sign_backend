<?php
return [
    'bundle' => 'com.sign2digital.app',
    'team' => env('M25_APPLE_TEAM_ID','GQAS52N9NZ'),
    'signin_key_id' => env('M25_APPLE_SIGNIN_KEY_ID',''),
    'signin_key_path' => env('M25_APPLE_SIGNIN_KEY_PATH',''),
    'store_enabled' => (bool) env('M25_STOREKIT_ENABLED',false),
    'store_environment' => env('M25_STOREKIT_ENVIRONMENT','Sandbox'),
    'store_issuer' => env('M25_STOREKIT_ISSUER_ID',''),
    'store_key_id' => env('M25_STOREKIT_KEY_ID',''),
    'store_key_path' => env('M25_STOREKIT_KEY_PATH',''),
    'products' => [
        'monthly' => env('M25_STOREKIT_MONTHLY','com.sign2digital.app.business.monthly'),
        'yearly' => env('M25_STOREKIT_YEARLY','com.sign2digital.app.business.yearly'),
    ],
];
