<?php

namespace App\Domain\Accounting\DTOs;

use App\Domain\Accounting\Enums\AccountType;

readonly class CreateAccountDTO
{
    public function __construct(
        public int $teamId,
        public string $code,
        public string $name,
        public AccountType $type,
        public ?string $description = null,
        public ?int $parentId = null,
        public bool $isSystem = false,
    ) {}
}
