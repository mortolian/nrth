<?php

namespace Tests\Feature\Upgrade;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckReleaseCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_release_skips_github_when_disabled(): void
    {
        Http::fake();
        Config::set('nrth.releases.check_enabled', false);

        $this->artisan('nrth:check-release')
            ->assertSuccessful()
            ->expectsOutputToContain('Installed:')
            ->expectsOutputToContain('NRTH_RELEASE_CHECK is disabled');

        Http::assertNothingSent();
    }

    public function test_check_release_reports_an_available_update(): void
    {
        Config::set('nrth.releases.check_enabled', true);
        Http::fake([
            'https://api.github.com/repos/mortolian/nrth/releases/latest' => Http::response([
                'tag_name' => 'v9.9.9',
                'html_url' => 'https://github.com/mortolian/nrth/releases/tag/v9.9.9',
            ], 200),
        ]);

        $this->artisan('nrth:check-release')
            ->assertSuccessful()
            ->expectsOutputToContain('Latest GitHub Release: 9.9.9')
            ->expectsOutputToContain('Update available:');
    }
}
