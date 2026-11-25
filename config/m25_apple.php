<?php
return [
    'bundle' => 'com.sign2digital.app',
    'team' => env('M25_APPLE_TEAM_ID','GQAS52N9NZ'),
    'signin_key_id' => env('M25_APPLE_SIGNIN_KEY_ID',''),
    'signin_key_path' => env('M25_APPLE_SIGNIN_KEY_PATH',''),
    'signin_require_exchange' => (bool) env('M25_APPLE_SIGNIN_REQUIRE_EXCHANGE',false),
];
