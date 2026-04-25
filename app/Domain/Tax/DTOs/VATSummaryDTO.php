<?php

namespace App\Domain\Tax\DTOs;

use Brick\Money\Money;
use Carbon\CarbonInterface;

readonly class VATSummaryDTO
{
    public function __construct(
        public Money $outputVAT,
        public Money $inputVAT,
        public Money $netVAT,
        public CarbonInterface $periodStart,
        public CarbonInterface $periodEnd,
    ) {}
}
