<?php

namespace App\Domain\SampleData;

final class SampleRun
{
    /**
     * @param  array<string, int>  $chapterMilliseconds
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public readonly SampleContext $context,
        public readonly int $milliseconds,
        public readonly array $chapterMilliseconds,
        public readonly array $counts,
    ) {}
}
