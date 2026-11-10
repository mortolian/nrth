<?php

namespace App\Domain\SampleData;

interface SampleChapter
{
    public function key(): string;

    public function seed(SampleContext $context): void;
}
