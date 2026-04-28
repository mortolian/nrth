<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Http\Controllers\Controller;
use App\Support\FrankfurterExchangeRates;
use App\Support\Iso4217Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Throwable;

class ExchangeRateController extends Controller
{
    /**
     * Frankfurter v2 rate (free, no API key). Optional {@code date} (Y-m-d) for historical ECB-style rates.
     *
     * @see https://www.frankfurter.app/docs/
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
            'to' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = strtoupper($validated['from']);
        $to = strtoupper($validated['to']);
        $asOfDate = $validated['date'] ?? null;

        if ($from === $to) {
            return response()->json([
                'rate' => 1.0,
                'date' => $asOfDate ?? now()->toDateString(),
                'source' => 'identity',
            ]);
        }

        $cacheKey = 'frankfurter:v2:rate:'.$from.':'.$to.':'.($asOfDate ?? 'latest');

        try {
            /** @var array{rate: float, date: string} $payload */
            $payload = Cache::remember($cacheKey, now()->addHour(), function () use ($from, $to, $asOfDate): array {
                return FrankfurterExchangeRates::fetchPairRate($from, $to, $asOfDate);
            });
        } catch (Throwable) {
            return response()->json([
                'message' => __('Exchange rate unavailable for this currency pair. Frankfurter may not publish a rate between these codes, or the service returned an error.'),
            ], 422);
        }

        return response()->json([
            'rate' => $payload['rate'],
            'date' => $payload['date'],
            'source' => 'frankfurter',
        ]);
    }
}
