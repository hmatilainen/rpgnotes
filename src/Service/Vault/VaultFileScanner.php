<?php

declare(strict_types=1);

namespace App\Service\Vault;

final class VaultFileScanner
{
    /**
     * @param string[] $excludedTopLevelDirs
     * @return string[] absolute file paths of .md files to index, sorted
     */
    public function scan(string $vaultRoot, array $excludedTopLevelDirs): array
    {
        $vaultRoot = rtrim($vaultRoot, '/');
        $skip = array_map('mb_strtolower', $excludedTopLevelDirs);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vaultRoot, \FilesystemIterator::SKIP_DOTS)
        );

        $results = [];

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            $relative = ltrim(str_replace($vaultRoot, '', $file->getPathname()), '/');
            $topLevel = explode('/', $relative)[0];

            if (\in_array(mb_strtolower($topLevel), $skip, true)) {
                continue;
            }

            $results[] = $file->getPathname();
        }

        sort($results);

        return $results;
    }
}
