<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Instance operators (break-glass)
    |--------------------------------------------------------------------------
    |
    | Optional comma-separated login emails that may manage whole-install backups
    | in addition to users with is_instance_operator. The matching user must have
    | email_verified_at set. New accounts persist that timestamp at creation
    | (MustVerifyEmail is unused). Changing profile email re-trusts a safe
    | address, but leaves it unset when the new mailbox matches a pending
    | invitation or NRTH_OPERATOR_EMAILS. Prefer Settings → Instance for
    | day-to-day operator management. The first created user is promoted
    | automatically; existing installs can run:
    | php artisan nrth:promote-first-operator
    |
    */

    'operator_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('NRTH_OPERATOR_EMAILS', '')),
    ))),

];
