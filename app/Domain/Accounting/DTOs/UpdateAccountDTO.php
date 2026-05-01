<?php

namespace App\Domain\Accounting\DTOs;

readonly class UpdateAccountDTO
{
    /**
     * @param  int|null|Unspecified  $parentId  int = new parent, null = clear parent (when provided), Unspecified = leave as-is
     */
    public function __construct(
        public string|Unspecified $name = Unspecified::Value,
        public string|Unspecified $code = Unspecified::Value,
        public string|null|Unspecified $description = Unspecified::Value,
        public int|null|Unspecified $parentId = Unspecified::Value,
        public bool|Unspecified $isActive = Unspecified::Value,
    ) {}
}
