<?php

return [
    // Confirm the legal operator and privacy contact before publishing store listings.
    'operator' => env('SITE_LEGAL_OPERATOR', 'Sign2Digital'),
    'privacy_email' => env('SITE_PRIVACY_EMAIL'),
    'operator_address' => env('SITE_LEGAL_ADDRESS'),
];
