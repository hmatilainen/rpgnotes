<?php

declare(strict_types=1);

namespace App\Service\Markdown;

/**
 * Turns Obsidian image embeds (![[file.png]], ![[file.png|300]]) into standard
 * Markdown images pointing at the /media route. Must run before wikilink
 * transformation, otherwise the embed is mistaken for a plain wikilink.
 */
final class ImageEmbedTransformer
{
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg'];

    public function transform(string $content): string
    {
        $pattern = sprintf(
            '/!\[\[([^\]|]+\.(?:%s))(?:\|[^\]]*)?\]\]/iu',
            implode('|', self::EXTENSIONS)
        );

        $result = preg_replace_callback(
            $pattern,
            static function (array $matches): string {
                $filename = basename(trim($matches[1]));
                $alt = str_replace(['[', ']'], '', pathinfo($filename, PATHINFO_FILENAME));

                return sprintf('![%s](/media/%s)', $alt, rawurlencode($filename));
            },
            $content
        );

        return $result ?? $content;
    }
}
