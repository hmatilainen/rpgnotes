<?php

declare(strict_types=1);

namespace App\Service\Vault;

final readonly class ReportsFolder
{
    public string $name;

    public function __construct(string $name)
    {
        $name = trim($name, '/\\');

        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new \InvalidArgumentException('REPORTS_FOLDER must be a single top-level vault folder name.');
        }

        $this->name = $name;
    }
}
