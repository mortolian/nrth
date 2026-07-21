<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Instance operators (break-glass)
    |--------------------------------------------------------------------------
    |
    | Optional comma-separated login emails that may manage whole-install backups
    | in addition to users with is_instance_operator. Prefer Settings → Instance
    | for day-to-day operator management. The first registered user is promoted
    | automatically; existing installs can run: php artisan nrth:promote-first-operator
    |
    */

    'operator_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('NRTH_OPERATOR_EMAILS', '')),
    ))),

];
