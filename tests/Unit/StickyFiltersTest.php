<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\StickyFilters;
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

    /**
     * @param  array<string, mixed>  $query
     */
    private function requestWithSession(array $query): Request
    {
        $request = Request::create('/example', 'GET', $query);
        $request->setLaravelSession($this->app['session.store']);
        $user = new User;
        $user->current_team_id = 7;
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return $request;
    }
}
