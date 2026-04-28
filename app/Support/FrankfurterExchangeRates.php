<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Frankfurter v2 — free reference rates (no API key).
 *
 * @see https://www.frankfurter.app/docs/
 *
 * @phpstan-type RatePayload array{rate: float, date: string}
 */
final class FrankfurterExchangeRates
{
    /**
     * How many units of {@code $quote} equal one unit of {@code $base} (same as API `rate` field).
     *
     * @param  string|null  $asOfDate  ISO date (Y-m-d) for historical ECB-style rates, or null for latest.
     * @return RatePayload
     *
     * @throws \RuntimeException When the request fails or the response is invalid.
     */
    public static function fetchPairRate(string $base, string $quote, ?string $asOfDate = null): array
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);

        if ($base === $quote) {
            return [
                'rate' => 1.0,
                'date' => $asOfDate ?? now()->toDateString(),
            ];
        }

        $url = sprintf(
            'https://api.frankfurter.dev/v2/rate/%s/%s',
            rawurlencode($base),
            rawurlencode($quote),
        );

        if ($asOfDate !== null && $asOfDate !== '') {
            $url .= '?date='.rawurlencode($asOfDate);
        }

        $response = Http::timeout(12)
            ->acceptJson()
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Frankfurter HTTP '.$response->status());
        }

        /** @var array<string, mixed>|null $data */
        $data = $response->json();
        if (! is_array($data) || ! isset($data['rate'])) {
            throw new \RuntimeException('Invalid Frankfurter payload');
        }

        $rate = (float) $data['rate'];
        if ($rate <= 0 || ! is_finite($rate)) {
            throw new \RuntimeException('Invalid rate value');
        }

        return [
            'rate' => $rate,
            'date' => isset($data['date']) ? (string) $data['date'] : (string) ($asOfDate ?? now()->toDateString()),
        ];
    }
}
