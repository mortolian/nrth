<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Http\Controllers\Controller;
use App\Support\Iso4217Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Throwable;

class ExchangeRateController extends Controller
{
    /**
     * Latest rate from Frankfurter v2 (free, no API key; multiple central bank sources).
     *
     * @see https://www.frankfurter.app/docs/
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
            'to' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
        ]);

        $from = strtoupper($validated['from']);
        $to = strtoupper($validated['to']);

        if ($from === $to) {
            return response()->json([
                'rate' => 1.0,
                'date' => now()->toDateString(),
                'source' => 'identity',
            ]);
        }

        $cacheKey = 'frankfurter:v2:rate:'.$from.':'.$to;

        try {
            $payload = Cache::remember($cacheKey, now()->addHour(), function () use ($from, $to): array {
                $url = sprintf('https://api.frankfurter.dev/v2/rate/%s/%s', rawurlencode($from), rawurlencode($to));

                $response = Http::timeout(12)
                    ->acceptJson()
                    ->get($url);

                if (! $response->successful()) {
                    throw new \RuntimeException('Frankfurter HTTP '.$response->status());
                }

                /** @var array<string, mixed>|null $data */
                $data = $response->json();
                if (! is_array($data)) {
                    throw new \RuntimeException('Invalid Frankfurter payload');
                }

                if (! isset($data['rate'])) {
                    throw new \RuntimeException('Missing rate in Frankfurter response');
                }

                $rate = (float) $data['rate'];
                if ($rate <= 0 || ! is_finite($rate)) {
                    throw new \RuntimeException('Invalid rate value');
                }

                return [
                    'rate' => $rate,
                    'date' => isset($data['date']) ? (string) $data['date'] : now()->toDateString(),
                ];
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
