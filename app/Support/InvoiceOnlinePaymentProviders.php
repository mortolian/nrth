<?php

namespace App\Support;

use App\Domain\Invoicing\Models\Invoice;
use App\Models\Team;

final class InvoiceOnlinePaymentProviders
{
    /**
     * @param  array<string, mixed>  $mergedCompanySettings
     */
    public static function paymentPagesEnabledForSettings(array $mergedCompanySettings): bool
    {
        return filter_var($mergedCompanySettings['payment_pages_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    public static function paymentPagesEnabledForTeam(?Team $team): bool
    {
        if ($team === null) {
            return false;
        }

        return self::paymentPagesEnabledForSettings($team->mergedCompanySettings());
    }

    /**
     * @return list<string>
     */
    public static function enabledForInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('team');
        $team = $invoice->team;
        if (! self::paymentPagesEnabledForTeam($team)) {
            return [];
        }

        $settings = $team->mergedCompanySettings();

        /** @var array<string, mixed> $gateways */
        $gateways = is_array($settings['payment_gateways'] ?? null) ? $settings['payment_gateways'] : [];
        $currency = Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR'));

        $providers = [];

        /** @var array<string, mixed> $stripe */
        $stripe = is_array($gateways['stripe'] ?? null) ? $gateways['stripe'] : [];
        $stripeSecret = isset($stripe['secret_key']) && is_string($stripe['secret_key']) ? trim($stripe['secret_key']) : '';
        if (($stripe['enabled'] ?? false) && $stripeSecret !== '') {
            $providers[] = 'stripe';
        }

        /** @var array<string, mixed> $payfast */
        $payfast = is_array($gateways['payfast'] ?? null) ? $gateways['payfast'] : [];
        $mid = isset($payfast['merchant_id']) && is_string($payfast['merchant_id']) ? trim($payfast['merchant_id']) : '';
        $mkey = isset($payfast['merchant_key']) && is_string($payfast['merchant_key']) ? trim($payfast['merchant_key']) : '';
        if ($currency === 'ZAR' && ($payfast['enabled'] ?? false) && $mid !== '' && $mkey !== '') {
            $providers[] = 'payfast';
        }

        return $providers;
    }
}
