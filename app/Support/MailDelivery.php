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

    /**
     * @return array{0: string, 1: string} flash key + message
     */
    public static function invoiceEmailedFlash(string $email, bool $reminder = false, bool $resent = false): array
    {
        if (self::isLoggingOnly()) {
            return [
                'warning',
                $reminder
                    ? __('Reminder queued for :email, but MAIL_MAILER is “log” so no email was delivered. Check Mailpit or MAIL_* settings.', ['email' => $email])
                    : __('Invoice queued for :email, but MAIL_MAILER is “log” so no email was delivered. Check Mailpit or MAIL_* settings.', ['email' => $email]),
            ];
        }

        if ($reminder) {
            return [
                'success',
                __('Payment reminder sent to :email.', ['email' => $email]),
            ];
        }

        if ($resent) {
            return [
                'success',
                __('Invoice resent to :email.', ['email' => $email]),
            ];
        }

        return [
            'success',
            __('Invoice sent to :email.', ['email' => $email]),
        ];
    }
}
