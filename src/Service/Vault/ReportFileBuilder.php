<?php

declare(strict_types=1);

namespace App\Service\Vault;

use Symfony\Component\Yaml\Yaml;

final class ReportFileBuilder
{
    public function buildVaultPath(
        int $reportNumber,
        \DateTimeImmutable $sessionDate,
        string $title,
    ): string {
        $range = $this->rangeFolder($reportNumber);
        $datePart = $sessionDate->format('j.n.Y');
        $safeTitle = $this->sanitizeTitle($title);

        return sprintf('Reports/%s/Report-%d %s %s.md', $range, $reportNumber, $datePart, $safeTitle);
    }

    public function buildContent(
        string $title,
        string $body,
        string $authorUsername,
        \DateTimeImmutable $publishedAt,
    ): string {
        $trimmedTitle = trim($title);
        $trimmedBody = rtrim($body);

        $frontmatter = trim(Yaml::dump([
            'published_at' => $publishedAt->format(\DateTimeInterface::ATOM),
            'author' => $authorUsername,
        ], 2, 2));

        return sprintf(
            "---\n%s\n---\n\n## %s\n\n%s\n",
            $frontmatter,
            $trimmedTitle,
            $trimmedBody
        );
    }

    private function rangeFolder(int $reportNumber): string
    {
        $start = (int) (floor(($reportNumber - 1) / 10) * 10) + 1;
        $end = $start + 9;

        return sprintf('%d-%d', $start, $end);
    }

    private function sanitizeTitle(string $title): string
    {
        $sanitized = preg_replace('/[\/\\\\:*?"<>|]/u', '', trim($title)) ?? '';
        $sanitized = preg_replace('/\s+/u', ' ', $sanitized) ?? '';
        $sanitized = trim($sanitized);

        if ($sanitized === '') {
            return 'Session';
        }

        if (mb_strlen($sanitized) > 120) {
            $sanitized = mb_substr($sanitized, 0, 120);
            $sanitized = rtrim($sanitized);
        }

        return $sanitized;
    }
}
