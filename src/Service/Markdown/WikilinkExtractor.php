<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class WikilinkExtractor
{
    /**
     * @return string[]
     */
    public function extractTargets(string $content): array
    {
        if (preg_match_all('/\[\[([^\]|]+)(?:\|[^\]]+)?\]\]/u', $content, $matches) !== false) {
            return array_values(array_unique(array_map(trim(...), $matches[1])));
        }

        return [];
    }

    /**
     * @return list<array{path: string, slug: string, title: string}>
     */
    public function resolveVisible(array $targets, WikilinkIndex $index): array
    {
        $resolved = [];

        foreach ($targets as $target) {
            $match = $index->resolve($target);
            if ($match === null) {
                continue;
            }

            $resolved[] = [
                'path' => $match->vaultPath,
                'slug' => $match->slug,
                'title' => $match->title,
            ];
        }

        return $resolved;
    }
}
