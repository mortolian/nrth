<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Remembers list searches and filters for the signed-in user and business.
 *
 * A request that includes any of the screen's keys replaces the saved set
 * (omitted keys return to their defaults, which is how Clear works).
 * A later visit with none of those keys restores the saved set until it expires.
 * `reset=1` forgets the saved set.
 *
 * Profile preferences `sticky_filters_enabled` and `sticky_filters_minutes`
 * turn this off or change how long a saved set lasts. The clock starts when
 * the filter is applied.
 */
final class StickyFilters
{
    public const DEFAULT_MINUTES = 10;

    public const MAX_MINUTES = 1440;

    /**
     * @param  array<string, mixed>  $defaults
     */
    public static function remember(Request $request, string $screen, array $defaults): void
    {
        $teamId = (int) ($request->user()?->current_team_id ?? 0);
        $sessionKey = 'sticky_filters.'.$screen.'.'.$teamId;
        $keys = array_keys($defaults);

        if ($request->exists('reset') || ! self::enabled($request)) {
            $request->session()->forget($sessionKey);
            if ($request->exists('reset')) {
                $request->replace($request->except([...$keys, 'reset']));
            }

            return;
        }

        if ($request->hasAny($keys)) {
            $resolved = [];
            foreach ($defaults as $key => $default) {
                if (! $request->exists($key)) {
                    $resolved[$key] = $default;

                    continue;
                }

                $value = $request->input($key);
                $resolved[$key] = ($value === '' || $value === null) ? $default : $value;
            }

            self::put($request, $sessionKey, $resolved);
            self::mergeOntoRequest($request, $resolved);

            return;
        }

        $stored = self::pull($request, $sessionKey);
        if ($stored === null) {
            return;
        }

        $resolved = $defaults;
        foreach ($keys as $key) {
            if (array_key_exists($key, $stored)) {
                $resolved[$key] = $stored[$key];
            }
        }

        self::mergeOntoRequest($request, $resolved);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function put(Request $request, string $sessionKey, array $values): void
    {
        if (! self::enabled($request)) {
            $request->session()->forget($sessionKey);

            return;
        }

        $request->session()->put($sessionKey, [
            'applied_at' => now()->getTimestamp(),
            'values' => $values,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function pull(Request $request, string $sessionKey): ?array
    {
        if (! self::enabled($request)) {
            $request->session()->forget($sessionKey);

            return null;
        }

        $stored = $request->session()->get($sessionKey);
        if (! is_array($stored) || ! isset($stored['values']) || ! is_array($stored['values'])) {
            if (is_array($stored)) {
                $request->session()->forget($sessionKey);
            }

            return null;
        }

        $appliedAt = (int) ($stored['applied_at'] ?? 0);
        if ($appliedAt <= 0 || now()->getTimestamp() - $appliedAt >= self::ttlSeconds($request)) {
            $request->session()->forget($sessionKey);

            return null;
        }

        /** @var array<string, mixed> $values */
        $values = $stored['values'];

        return $values;
    }

    /**
     * @return array{enabled: bool, minutes: int}
     */
    public static function forInertia(mixed $user): array
    {
        $preferences = $user instanceof User ? $user->mergedPreferences() : User::defaultPreferences();

        return [
            'enabled' => (bool) ($preferences['sticky_filters_enabled'] ?? true),
            'minutes' => self::minutesFrom($preferences),
        ];
    }

    public static function enabled(Request $request): bool
    {
        return (bool) (self::preferences($request)['sticky_filters_enabled'] ?? true);
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private static function mergeOntoRequest(Request $request, array $resolved): void
    {
        $payload = [];
        foreach ($resolved as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $payload[$key] = $value;
        }

        if ($payload !== []) {
            $request->merge($payload);
        }
    }

    private static function ttlSeconds(Request $request): int
    {
        return self::minutesFrom(self::preferences($request)) * 60;
    }

    /**
     * @param  array<string, mixed>  $preferences
     */
    private static function minutesFrom(array $preferences): int
    {
        $minutes = (int) ($preferences['sticky_filters_minutes'] ?? self::DEFAULT_MINUTES);

        return max(1, min(self::MAX_MINUTES, $minutes));
    }

    /**
     * @return array<string, mixed>
     */
    private static function preferences(Request $request): array
    {
        $user = $request->user();

        return $user instanceof User ? $user->mergedPreferences() : User::defaultPreferences();
    }
}
