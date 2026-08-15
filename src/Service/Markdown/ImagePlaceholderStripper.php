<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class ImagePlaceholderStripper
{
    public function strip(string $content): string
    {
        return preg_replace('/\[img:\d+\]/', '', $content) ?? $content;
    }
}
