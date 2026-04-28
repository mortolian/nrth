<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Models\Invoice;
use App\Support\FrankfurterExchangeRates;
use App\Support\Iso4217Currencies;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Persists the company (book) currency equivalent of the invoice total using Frankfurter
 * at the invoice issue date, for reporting and internal display.
 */
final class InvoiceCompanyCurrencySnapshot
{
    public function sync(Invoice $invoice): void
    {
        $invoice->loadMissing('team');
        $team = $invoice->team;
        if ($team === null) {
            return;
        }

        $companyCurrency = Iso4217Currencies::normalize(
            (string) ($team->mergedCompanySettings()['invoice_default_currency'] ?? 'ZAR')
        );
        $invoiceCurrency = Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR'));
        $issueDate = $invoice->issue_date?->toDateString() ?? now()->toDateString();
        $totalCents = (int) $invoice->getRawOriginal('total_cents');

        if ($invoiceCurrency === $companyCurrency) {
            $invoice->forceFill([
                'company_currency_code' => $companyCurrency,
                'fx_rate_invoice_to_company' => '1',
                'fx_rate_date' => $issueDate,
                'total_company_currency_cents' => $totalCents,
            ])->saveQuietly();

            return;
        }

        $cacheKey = 'frankfurter:v2:rate:'.$invoiceCurrency.':'.$companyCurrency.':'.$issueDate;

        try {
            /** @var array{rate: float, date: string} $rateData */
            $rateData = Cache::remember($cacheKey, now()->addHour(), function () use ($invoiceCurrency, $companyCurrency, $issueDate): array {
                return FrankfurterExchangeRates::fetchPairRate($invoiceCurrency, $companyCurrency, $issueDate);
            });
        } catch (Throwable) {
            $invoice->forceFill([
                'company_currency_code' => $companyCurrency,
                'fx_rate_invoice_to_company' => null,
                'fx_rate_date' => null,
                'total_company_currency_cents' => null,
            ])->saveQuietly();

            return;
        }

        $rate = $rateData['rate'];
        $companyTotalCents = (int) round($totalCents * $rate);

        $invoice->forceFill([
            'company_currency_code' => $companyCurrency,
            'fx_rate_invoice_to_company' => number_format($rate, 10, '.', ''),
            'fx_rate_date' => $rateData['date'],
            'total_company_currency_cents' => $companyTotalCents,
        ])->saveQuietly();
    }
}
