<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class ReportFilenameParser
{
    public function parse(string $filename): ?ReportMeta
    {
        $name = preg_replace('/\.md$/i', '', $filename) ?? $filename;

        if (preg_match('/^Report-(\d+)\s+(.+)$/u', $name, $matches) !== 1) {
            return null;
        }

        [, $number, $rest] = $matches;

        $sessionDate = null;
        $title = $rest;

        // Only the newer-style reports use a fully numeric "D.M.YYYY" date
        // right after the report number (e.g. "20.2.1367"). Older reports
        // spell the date out in Finnish ("Tiistai 28. lokakuuta 1366") and
        // simply get no parsed date — the report number alone is enough to
        // list and order them.
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{3,4})\b\s*(.*)$/u', $rest, $dateMatches) === 1) {
            [, $day, $month, $year, $titleAfterDate] = $dateMatches;

            $parsed = \DateTimeImmutable::createFromFormat(
                '!d.m.Y',
                sprintf('%02d.%02d.%04d', (int) $day, (int) $month, (int) $year)
            );

            if ($parsed !== false) {
                $sessionDate = $parsed;
                $title = $titleAfterDate;
            }
        }

        return new ReportMeta(
            reportNumber: (int) $number,
            sessionDate: $sessionDate,
            title: trim($title),
        );
    }
}
