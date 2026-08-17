<?php

declare(strict_types=1);

namespace App\Service\SessionReport;

final class InGameDateParser
{
    public function parse(string $value): ?\DateTimeImmutable
    {
        $trimmed = trim($value);

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{3,4})$/u', $trimmed, $matches) !== 1) {
            return null;
        }

        [, $day, $month, $year] = $matches;

        $parsed = \DateTimeImmutable::createFromFormat(
            '!d.m.Y',
            sprintf('%02d.%02d.%04d', (int) $day, (int) $month, (int) $year)
        );

        if ($parsed === false) {
            return null;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $parsed;
    }
}
