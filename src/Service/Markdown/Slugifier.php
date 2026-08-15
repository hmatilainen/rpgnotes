<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class Slugifier
{
    private const TRANSLITERATION = [
        'ä' => 'a', 'ö' => 'o', 'å' => 'a',
        'Ä' => 'a', 'Ö' => 'o', 'Å' => 'a',
    ];

    public function slugifyPath(string $vaultRelativePath): string
    {
        $withoutExtension = preg_replace('/\.md$/i', '', $vaultRelativePath) ?? $vaultRelativePath;
        $segments = explode('/', $withoutExtension);

        return implode('/', array_map(fn (string $segment) => $this->slugifySegment($segment), $segments));
    }

    private function slugifySegment(string $segment): string
    {
        $transliterated = strtr($segment, self::TRANSLITERATION);
        $lowered = mb_strtolower($transliterated);
        $dashed = preg_replace('/[^a-z0-9]+/u', '-', $lowered) ?? $lowered;

        return trim($dashed, '-');
    }
}
