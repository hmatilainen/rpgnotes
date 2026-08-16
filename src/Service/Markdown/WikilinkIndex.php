<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class WikilinkIndex
{
    /** @var array<string, NoteDraft> */
    private array $byPath = [];

    /** @var array<string, NoteDraft[]> */
    private array $byFilename = [];

    /**
     * @param NoteDraft[] $drafts
     */
    public function __construct(array $drafts)
    {
        foreach ($drafts as $draft) {
            $this->byPath[mb_strtolower($draft->vaultPath)] = $draft;
            $filename = mb_strtolower(basename($draft->vaultPath));
            $this->byFilename[$filename][] = $draft;
        }
    }

    public function resolve(string $target): ?NoteDraft
    {
        $normalizedTarget = trim($target);

        $byPath = $this->byPath[mb_strtolower($normalizedTarget . '.md')] ?? null;
        if ($byPath !== null) {
            return $byPath;
        }

        $filename = mb_strtolower(basename($normalizedTarget) . '.md');
        $candidates = $this->byFilename[$filename] ?? [];

        if (\count($candidates) === 0) {
            return null;
        }

        usort($candidates, static fn (NoteDraft $a, NoteDraft $b) => strcmp($a->vaultPath, $b->vaultPath));

        return $candidates[0];
    }
}
