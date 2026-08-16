<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class WikilinkTransformer
{
    public function transform(string $content, WikilinkIndex $index): string
    {
        $result = preg_replace_callback(
            '/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u',
            function (array $matches) use ($index): string {
                $target = trim($matches[1]);
                $display = isset($matches[2]) ? trim($matches[2]) : basename($target);
                $resolved = $index->resolve($target);

                if ($resolved === null) {
                    return $display;
                }

                return sprintf('[%s](/notes/%s)', $display, $resolved->slug);
            },
            $content
        );

        return $result ?? $content;
    }
}
