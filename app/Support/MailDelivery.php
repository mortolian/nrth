<?php

namespace App\Support;

final class MailDelivery
{
    public static function isLoggingOnly(): bool
    {
        return config('mail.default') === 'log';
    }

    public static function invitationSentFlash(string $email): array
    {
        if (self::isLoggingOnly()) {
            return [
                'warning',
                __('Invitation created for :email, but MAIL_MAILER is “log” so no email was delivered. Use Mailpit (http://localhost:8025) or set real MAIL_* values. See docs/DEVELOPMENT.md.', [
                    'email' => $email,
                ]),
            ];
        }

        return [
            'success',
            __('Invitation sent to :email.', ['email' => $email]),
        ];
    }
}
