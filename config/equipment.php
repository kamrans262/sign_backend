<?php
return [
    // Off unless the business explicitly enables scheduled outbound equipment email.
    'automatic_enabled' => (bool) env('EQUIPMENT_LOAN_AUTO_REMINDERS_ENABLED', false),
    'automatic_max_per_run' => (int) env('EQUIPMENT_LOAN_AUTO_REMINDERS_MAX', 40),
];
