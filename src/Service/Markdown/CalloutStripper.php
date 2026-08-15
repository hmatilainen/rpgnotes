<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class CalloutStripper
{
    public function strip(string $content): string
    {
        $lines = explode("\n", $content);
        $result = [];
        $count = \count($lines);
        $i = 0;

        while ($i < $count) {
            if (preg_match('/^>\s*\[!\w+\]/', $lines[$i]) === 1) {
                ++$i;
                while ($i < $count && preg_match('/^>/', $lines[$i]) === 1) {
                    ++$i;
                }
                // Add empty line to replace callout unless next line is already blank
                // (but do add if we're at the start of document)
                if ($i >= $count || $lines[$i] !== '' || empty($result)) {
                    $result[] = '';
                }
                continue;
            }

            $result[] = $lines[$i];
            ++$i;
        }

        return implode("\n", $result);
    }
}
