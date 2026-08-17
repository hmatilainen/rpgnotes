<?php

declare(strict_types=1);

namespace App\Service\Markdown;

use Symfony\Component\Yaml\Yaml;

final class FrontmatterParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $content): array
    {
        $normalized = ltrim($content, "\xEF\xBB\xBF");

        if (!str_starts_with($normalized, '---')) {
            return [];
        }

        if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n/s', $normalized, $matches) !== 1) {
            return [];
        }

        $parsed = Yaml::parse($matches[1]);

        return \is_array($parsed) ? $parsed : [];
    }

    public function parsePublishedAt(string $content): ?\DateTimeImmutable
    {
        $data = $this->parse($content);

        if (!isset($data['published_at']) || !\is_string($data['published_at'])) {
            return null;
        }

        try {
            return new \DateTimeImmutable($data['published_at']);
        } catch (\Exception) {
            return null;
        }
    }
}
