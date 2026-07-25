<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Models\Invoice;
use App\Support\FrankfurterExchangeRates;
use App\Support\Iso4217Currencies;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Persists the business (book) currency equivalent of the invoice total using Frankfurter
 * at the invoice issue date, for reporting and internal display.
 */
final class InvoiceBusinessCurrencySnapshot
{
    public function sync(Invoice $invoice): void
    {
        $invoice->loadMissing('team');
        $team = $invoice->team;
        if ($team === null) {
            return;
        }

        $businessCurrency = Iso4217Currencies::normalize(
            (string) ($team->mergedBusinessSettings()['invoice_default_currency'] ?? 'ZAR')
        );
        $invoiceCurrency = Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR'));
        $issueDate = $invoice->issue_date?->toDateString() ?? now()->toDateString();
        $totalCents = (int) $invoice->getRawOriginal('total_cents');

        if ($invoiceCurrency === $businessCurrency) {
            $invoice->forceFill([
                'business_currency_code' => $businessCurrency,
                'fx_rate_invoice_to_business' => '1',
                'fx_rate_date' => $issueDate,
                'total_business_currency_cents' => $totalCents,
            ])->saveQuietly();

            return;
        }

        $cacheKey = 'frankfurter:v2:rate:'.$invoiceCurrency.':'.$businessCurrency.':'.$issueDate;

        try {
            /** @var array{rate: float, date: string} $rateData */
            $rateData = Cache::remember($cacheKey, now()->addHour(), function () use ($invoiceCurrency, $businessCurrency, $issueDate): array {
                return FrankfurterExchangeRates::fetchPairRate($invoiceCurrency, $businessCurrency, $issueDate);
            });
        } catch (Throwable) {
            $invoice->forceFill([
                'business_currency_code' => $businessCurrency,
                'fx_rate_invoice_to_business' => null,
                'fx_rate_date' => null,
                'total_business_currency_cents' => null,
            ])->saveQuietly();

            return;
        }

        $rate = $rateData['rate'];
        $businessTotalCents = (int) round($totalCents * $rate);

        $invoice->forceFill([
            'business_currency_code' => $businessCurrency,
            'fx_rate_invoice_to_business' => number_format($rate, 10, '.', ''),
            'fx_rate_date' => $rateData['date'],
            'total_business_currency_cents' => $businessTotalCents,
        ])->saveQuietly();
    }
}
