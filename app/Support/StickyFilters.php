<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Remembers list searches and filters for the signed-in user and business.
 *
 * A request that includes any of the screen's keys replaces the saved set
 * (omitted keys return to their defaults, which is how Clear works).
 * A later visit with none of those keys restores the saved set.
 * `reset=1` forgets the saved set.
 */
final class StickyFilters
{
    /**
     * @param  array<string, mixed>  $defaults
     */
    public static function remember(Request $request, string $screen, array $defaults): void
    {
        $teamId = (int) ($request->user()?->current_team_id ?? 0);
        $sessionKey = 'sticky_filters.'.$screen.'.'.$teamId;
        $keys = array_keys($defaults);

        if ($request->exists('reset')) {
            $request->session()->forget($sessionKey);
            $request->replace($request->except([...$keys, 'reset']));

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

            $request->session()->put($sessionKey, $resolved);
            self::mergeOntoRequest($request, $resolved);

            return;
        }

        $stored = $request->session()->get($sessionKey);
        if (! is_array($stored)) {
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
}
