<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\StickyFilters;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Tests\TestCase;

class StickyFiltersTest extends TestCase
{
    public function test_query_replaces_saved_filters_and_a_bare_visit_restores_them(): void
    {
        $request = $this->requestWithSession(['search' => 'acme', 'page' => '2']);

        StickyFilters::remember($request, 'clients.index', [
            'search' => null,
            'status' => 'active',
            'page' => 1,
        ]);

        $this->assertSame('acme', $request->input('search'));
        $this->assertSame('active', $request->input('status'));
        $this->assertSame('2', $request->input('page'));

        $again = $this->requestWithSession([]);
        StickyFilters::remember($again, 'clients.index', [
            'search' => null,
            'status' => 'active',
            'page' => 1,
        ]);

        $this->assertSame('acme', $again->input('search'));
        $this->assertSame('2', $again->input('page'));
    }

    public function test_a_new_query_drops_keys_that_were_left_out(): void
    {
        $this->requestWithSession(['search' => 'acme', 'status' => 'inactive']);
        StickyFilters::remember(request(), 'clients.index', [
            'search' => null,
            'status' => 'active',
        ]);

        $cleared = $this->requestWithSession(['status' => 'active', 'page' => '1']);
        StickyFilters::remember($cleared, 'clients.index', [
            'search' => null,
            'status' => 'active',
            'page' => 1,
        ]);

        $this->assertNull($cleared->input('search'));
        $this->assertSame('active', $cleared->input('status'));
    }

    public function test_reset_forgets_saved_filters(): void
    {
        $this->requestWithSession(['search' => 'acme']);
        StickyFilters::remember(request(), 'clients.index', ['search' => null]);

        $reset = $this->requestWithSession(['reset' => '1', 'search' => 'acme']);
        StickyFilters::remember($reset, 'clients.index', ['search' => null]);

        $this->assertNull($reset->input('search'));

        $bare = $this->requestWithSession([]);
        StickyFilters::remember($bare, 'clients.index', ['search' => null]);

        $this->assertNull($bare->input('search'));
    }

    public function test_saved_filters_expire_after_the_profile_time_limit(): void
    {
        Carbon::setTestNow('2026-10-12 12:00:00');

        try {
            $saved = $this->requestWithSession(['search' => 'acme'], [
                'sticky_filters_minutes' => 10,
            ]);
            StickyFilters::remember($saved, 'clients.index', [
                'search' => null,
                'page' => 1,
            ]);

            Carbon::setTestNow('2026-10-12 12:09:59');
            $stillThere = $this->requestWithSession([]);
            StickyFilters::remember($stillThere, 'clients.index', [
                'search' => null,
                'page' => 1,
            ]);
            $this->assertSame('acme', $stillThere->input('search'));

            Carbon::setTestNow('2026-10-12 12:10:00');
            $expired = $this->requestWithSession([]);
            StickyFilters::remember($expired, 'clients.index', [
                'search' => null,
                'page' => 1,
            ]);
            $this->assertNull($expired->input('search'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_disabled_preference_does_not_keep_filters(): void
    {
        $saved = $this->requestWithSession(['search' => 'acme'], [
            'sticky_filters_enabled' => false,
        ]);
        StickyFilters::remember($saved, 'clients.index', ['search' => null]);
        $this->assertSame('acme', $saved->input('search'));

        $again = $this->requestWithSession([], [
            'sticky_filters_enabled' => false,
        ]);
        StickyFilters::remember($again, 'clients.index', ['search' => null]);

        $this->assertNull($again->input('search'));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $preferences
     */
    private function requestWithSession(array $query, array $preferences = []): Request
    {
        $request = Request::create('/example', 'GET', $query);
        $request->setLaravelSession($this->app['session.store']);
        $user = new User;
        $user->current_team_id = 7;
        if ($preferences !== []) {
            $user->preferences = $preferences;
        }
        $this->app->instance('request', $request);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }
}
