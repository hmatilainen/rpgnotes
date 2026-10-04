<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Repository\HiddenPathRepository;
use App\Service\Markdown\ImageEmbedTransformer;
use App\Service\Vault\HiddenPathMatcher;

/**
 * Finds an image in the vault by bare filename, the way Obsidian resolves
 * ![[file.png]] embeds. Only image extensions are served, dot-directories and
 * excluded top-level dirs are skipped, and files under hidden paths are refused.
 */
final class VaultMediaResolver
{
    /**
     * @param string[] $excludedTopLevelDirs
     */
    public function __construct(
        private readonly string $vaultPath,
        private readonly array $excludedTopLevelDirs,
        private readonly HiddenPathRepository $hiddenPaths,
        private readonly HiddenPathMatcher $hiddenPathMatcher,
    ) {
    }

    public function resolve(string $filename): ?string
    {
        if ($filename === '' || $filename !== basename($filename) || str_contains($filename, "\0")) {
            return null;
        }

        if (!\in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ImageEmbedTransformer::EXTENSIONS, true)) {
            return null;
        }

        $root = rtrim($this->vaultPath, '/');
        if (!is_dir($root)) {
            return null;
        }

        $skip = array_map('mb_strtolower', $this->excludedTopLevelDirs);
        $hidden = $this->hiddenPaths->findAllPaths();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $candidates = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->isLink() || $file->getFilename() !== $filename) {
                continue;
            }

            $relative = ltrim(substr($file->getPathname(), \strlen($root)), '/');
            $segments = explode('/', $relative);

            if (\in_array(mb_strtolower($segments[0]), $skip, true)) {
                continue;
            }
            if (array_filter($segments, static fn (string $s) => str_starts_with($s, '.')) !== []) {
                continue;
            }
            if ($this->hiddenPathMatcher->isHidden($relative, $hidden)) {
                continue;
            }

            $candidates[] = $file->getPathname();
        }

        sort($candidates);

        return $candidates[0] ?? null;
    }
}
