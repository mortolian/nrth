<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Support\Upgrade\SchemaUpgradeStatus;
use App\Support\Version\GithubReleaseChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppVersionSharedPropTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shares_installed_version(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $expected = app(SchemaUpgradeStatus::class)->displayVersion();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('app_version.current', $expected)
                ->where('app_version.update_available', false)
                ->where('app_version.latest', null)
            );
    }

    public function test_dashboard_shares_update_available_when_github_is_newer(): void
    {
        Config::set('nrth.releases.check_enabled', true);
        Http::fake([
            'https://api.github.com/repos/mortolian/nrth/releases/latest' => Http::response([
                'tag_name' => 'v9.9.9',
                'html_url' => 'https://github.com/mortolian/nrth/releases/tag/v9.9.9',
            ], 200),
        ]);

        $user = User::factory()->withPersonalTeam()->create();

        app(GithubReleaseChecker::class)->refresh();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('app_version.update_available', true)
                ->where('app_version.latest', '9.9.9')
                ->where('app_version.url', 'https://github.com/mortolian/nrth/releases/tag/v9.9.9')
            );
    }

    public function test_login_does_not_call_github(): void
    {
        Config::set('nrth.releases.check_enabled', true);
        Http::fake();

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('app_version.update_available', false)
            );

        Http::assertNothingSent();
    }

    public function test_login_shares_installed_version(): void
    {
        $expected = app(SchemaUpgradeStatus::class)->displayVersion();

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('app_version.current', $expected)
            );
    }
}
