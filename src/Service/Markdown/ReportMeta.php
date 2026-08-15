<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class ReportMeta
{
    public function __construct(
        public readonly int $reportNumber,
        public readonly ?\DateTimeImmutable $sessionDate,
        public readonly string $title,
    ) {
    }
}
