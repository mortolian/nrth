<?php

namespace Tests\Unit\Support\Version;

use App\Support\Upgrade\SchemaUpgradeStatus;
use App\Support\Version\GithubReleaseChecker;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GithubReleaseCheckerTest extends TestCase
{
    #[Test]
    public function it_prefers_version_txt_for_display(): void
    {
        $status = app(SchemaUpgradeStatus::class);
        $fromFile = trim((string) file_get_contents(base_path('version.txt')));

        $this->assertSame(
            SchemaUpgradeStatus::normalizeVersion($fromFile),
            $status->displayVersion()
        );
        $this->assertSame('0.1.4', SchemaUpgradeStatus::normalizeVersion('v0.1.4'));
    }

    #[Test]
    public function it_does_not_call_github_when_release_check_is_disabled(): void
    {
        Http::fake();
        Config::set('nrth.releases.check_enabled', false);

        $payload = app(GithubReleaseChecker::class)->forInertia();

        $this->assertFalse($payload['update_available']);
        $this->assertNull($payload['latest']);
        $this->assertNotSame('', $payload['current']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_does_not_call_github_from_for_inertia(): void
    {
        Config::set('nrth.releases.check_enabled', true);
        Http::fake();

        $payload = app(GithubReleaseChecker::class)->forInertia();

        $this->assertFalse($payload['update_available']);
        $this->assertNotSame('', $payload['current']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_marks_an_update_when_github_latest_is_newer(): void
    {
        Config::set('nrth.releases.check_enabled', true);
        Config::set('nrth.releases.github_repository', 'mortolian/nrth');
        Http::fake([
            'https://api.github.com/repos/mortolian/nrth/releases/latest' => Http::response([
                'tag_name' => 'v9.9.9',
                'html_url' => 'https://github.com/mortolian/nrth/releases/tag/v9.9.9',
            ], 200),
        ]);

        app(GithubReleaseChecker::class)->refresh();
        $payload = app(GithubReleaseChecker::class)->forInertia();

        $this->assertTrue($payload['update_available']);
        $this->assertSame('9.9.9', $payload['latest']);
        $this->assertSame('https://github.com/mortolian/nrth/releases/tag/v9.9.9', $payload['url']);
    }

    #[Test]
    public function it_does_not_mark_an_update_when_github_latest_matches_installed(): void
    {
        $current = app(SchemaUpgradeStatus::class)->displayVersion();
        Config::set('nrth.releases.check_enabled', true);
        Http::fake([
            'https://api.github.com/repos/mortolian/nrth/releases/latest' => Http::response([
                'tag_name' => 'v'.$current,
                'html_url' => 'https://github.com/mortolian/nrth/releases/tag/v'.$current,
            ], 200),
        ]);

        app(GithubReleaseChecker::class)->refresh();
        $payload = app(GithubReleaseChecker::class)->forInertia();

        $this->assertFalse($payload['update_available']);
        $this->assertNull($payload['latest']);
    }

    #[Test]
    public function it_treats_github_errors_as_no_update(): void
    {
        Config::set('nrth.releases.check_enabled', true);
        Http::fake([
            'https://api.github.com/repos/mortolian/nrth/releases/latest' => Http::response('nope', 500),
        ]);

        app(GithubReleaseChecker::class)->refresh();
        $payload = app(GithubReleaseChecker::class)->forInertia();

        $this->assertFalse($payload['update_available']);
        $this->assertNull($payload['latest']);
    }
}
