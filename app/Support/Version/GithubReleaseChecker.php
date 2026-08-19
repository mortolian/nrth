<?php

namespace App\Support\Version;

use App\Support\Upgrade\SchemaUpgradeStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GithubReleaseChecker
{
    private const CACHE_KEY = 'nrth.github.latest_release';

    public function __construct(
        private readonly SchemaUpgradeStatus $status,
    ) {}

    /**
     * @return array{current: string, latest: string|null, update_available: bool, url: string|null, docs_url: string}
     */
    public function forInertia(): array
    {
        try {
            $current = $this->status->displayVersion();
            $latest = $this->latest();
            $available = $latest !== null && version_compare($latest->version, $current, '>');

            return [
                'current' => $current,
                'latest' => $available ? $latest->version : null,
                'update_available' => $available,
                'url' => $available ? $latest->htmlUrl : null,
                'docs_url' => $this->upgradeDocsUrl(),
            ];
        } catch (Throwable) {
            return $this->fallbackPayload();
        }
    }

    /**
     * Cached latest release only — never calls GitHub. Web/Inertia must stay offline-safe
     * (Octane + Guzzle to api.github.com can stall workers and block login).
     */
    public function latest(): ?GithubLatestRelease
    {
        if (! (bool) config('nrth.releases.check_enabled')) {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) ? $this->releaseFromCache($cached) : null;
    }

    public function refresh(): ?GithubLatestRelease
    {
        if (! (bool) config('nrth.releases.check_enabled')) {
            return null;
        }

        try {
            $fetched = $this->fetchLatest();
            $ttl = (int) ($fetched['ok']
                ? config('nrth.releases.cache_ttl_seconds')
                : config('nrth.releases.failure_cache_ttl_seconds'));

            Cache::put(self::CACHE_KEY, $fetched, max(1, $ttl));

            return $this->releaseFromCache($fetched);
        } catch (Throwable) {
            Cache::put(self::CACHE_KEY, ['ok' => false], (int) config('nrth.releases.failure_cache_ttl_seconds', 1800));

            return null;
        }
    }

    public function upgradeDocsUrl(): string
    {
        $repo = $this->repository();

        return $repo !== null
            ? 'https://github.com/'.$repo.'/blob/master/docs/UPGRADE.md'
            : 'https://github.com/mortolian/nrth/blob/master/docs/UPGRADE.md';
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private function releaseFromCache(array $cached): ?GithubLatestRelease
    {
        if (($cached['ok'] ?? false) !== true) {
            return null;
        }

        $version = SchemaUpgradeStatus::normalizeVersion((string) ($cached['version'] ?? ''));
        $url = (string) ($cached['html_url'] ?? '');

        if ($version === '' || $version === '0.0.0' || $url === '') {
            return null;
        }

        return new GithubLatestRelease($version, $url);
    }

    /**
     * @return array{ok: bool, version?: string, html_url?: string}
     */
    private function fetchLatest(): array
    {
        $repo = $this->repository();
        if ($repo === null) {
            return ['ok' => false];
        }

        try {
            $response = Http::timeout(3)
                ->connectTimeout(2)
                ->accept('application/vnd.github+json')
                ->withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->withUserAgent('nrth-self-host (https://github.com/'.$repo.')')
                ->get('https://api.github.com/repos/'.$repo.'/releases/latest');
        } catch (ConnectionException|Throwable) {
            return ['ok' => false];
        }

        if (! $response->successful()) {
            return ['ok' => false];
        }

        /** @var array<string, mixed>|null $data */
        $data = $response->json();
        if (! is_array($data)) {
            return ['ok' => false];
        }

        $tag = SchemaUpgradeStatus::normalizeVersion((string) ($data['tag_name'] ?? ''));
        $url = trim((string) ($data['html_url'] ?? ''));

        if ($tag === '' || $tag === '0.0.0' || $url === '' || ! str_starts_with($url, 'https://')) {
            return ['ok' => false];
        }

        return [
            'ok' => true,
            'version' => $tag,
            'html_url' => $url,
        ];
    }

    private function repository(): ?string
    {
        $repo = trim((string) config('nrth.releases.github_repository'));

        if ($repo === '' || ! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo)) {
            return null;
        }

        return $repo;
    }

    /**
     * @return array{current: string, latest: null, update_available: false, url: null, docs_url: string}
     */
    private function fallbackPayload(): array
    {
        $current = '0.0.0';
        try {
            $current = $this->status->displayVersion();
        } catch (Throwable) {
            $path = base_path('version.txt');
            if (is_file($path)) {
                $current = SchemaUpgradeStatus::normalizeVersion((string) file_get_contents($path));
            }
        }

        return [
            'current' => $current,
            'latest' => null,
            'update_available' => false,
            'url' => null,
            'docs_url' => 'https://github.com/mortolian/nrth/blob/master/docs/UPGRADE.md',
        ];
    }
}
