<?php

declare(strict_types=1);

namespace App\Service\Vault;

final class IndexResult
{
    public function __construct(
        public readonly int $updated,
        public readonly int $deleted,
    ) {
    }
}
