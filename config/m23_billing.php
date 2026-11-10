<?php

// All values are private server environment configuration. No activation,
// Stripe keys or live checkout are activated by default. Webhooks remain optional
// in the temporary polling-based test setup and can be enabled later.
return [
    'enabled' => filter_var(env('M23_STRIPE_ENABLED',false),FILTER_VALIDATE_BOOLEAN),
    'secret_key' => env('M23_STRIPE_SECRET_KEY') ?: env('STRIPE_SECRET',''),
    'webhook_secret' => env('M23_STRIPE_WEBHOOK_SECRET',''),
    'live_mode' => filter_var(env('M23_STRIPE_LIVE_MODE',false),FILTER_VALIDATE_BOOLEAN),
    'reconcile_enabled' => filter_var(env('M23_RECONCILE_ENABLED',env('M23_STRIPE_ENABLED',false)),FILTER_VALIDATE_BOOLEAN),
    'checkout_success_url' => env('M23_STRIPE_SUCCESS_URL',''),
    'checkout_cancel_url' => env('M23_STRIPE_CANCEL_URL',''),
    'portal_return_url' => env('M23_STRIPE_PORTAL_RETURN_URL',''),
    'api' => 'https://api.stripe.com/v1',
    'webhook_tolerance_seconds' => 300,
];
