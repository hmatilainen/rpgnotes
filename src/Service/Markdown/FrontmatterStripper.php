<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class FrontmatterStripper
{
    public function strip(string $content): string
    {
        $normalized = ltrim($content, "\xEF\xBB\xBF");

        if (!str_starts_with($normalized, '---')) {
            return $content;
        }

        if (preg_match('/^---\r?\n.*?\r?\n---\r?\n?/s', $normalized, $matches) === 1) {
            return substr($normalized, \strlen($matches[0]));
        }

        return $content;
    }
}
