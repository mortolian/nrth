<?php

namespace App\Support\Version;

final readonly class GithubLatestRelease
{
    public function __construct(
        public string $version,
        public string $htmlUrl,
    ) {}
}
