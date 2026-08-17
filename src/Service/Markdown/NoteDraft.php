<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class NoteDraft
{
    public function __construct(
        public readonly string $vaultPath,
        public readonly string $title,
        public string $slug,
        public readonly string $topLevelFolder,
        public string $strippedContent,
        public readonly ?int $reportNumber,
        public readonly ?\DateTimeImmutable $sessionDate,
        public readonly ?\DateTimeImmutable $publishedAt,
        public readonly bool $hidden,
    ) {
    }
}
