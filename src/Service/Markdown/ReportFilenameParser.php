<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class ReportFilenameParser
{
    public function parse(string $filename): ?ReportMeta
    {
        $name = preg_replace('/\.md$/i', '', $filename) ?? $filename;

        if (preg_match(
            '/^Report-(\d+)\s+(\d{1,2})\.(\d{1,2})\.(\d{3,4})\s+(.+)$/u',
            $name,
            $matches
        ) !== 1) {
            return null;
        }

        [, $number, $day, $month, $year, $title] = $matches;

        $sessionDate = \DateTimeImmutable::createFromFormat(
            '!d.m.Y',
            sprintf('%02d.%02d.%04d', (int) $day, (int) $month, (int) $year)
        );

        return new ReportMeta(
            reportNumber: (int) $number,
            sessionDate: $sessionDate !== false ? $sessionDate : null,
            title: trim($title),
        );
    }
}
