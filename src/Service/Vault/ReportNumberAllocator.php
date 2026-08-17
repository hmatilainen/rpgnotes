<?php

declare(strict_types=1);

namespace App\Service\Vault;

use App\Service\Markdown\ReportFilenameParser;

final class ReportNumberAllocator
{
    public function __construct(
        private readonly ReportFilenameParser $reportParser,
    ) {
    }

    public function allocateNext(string $vaultRoot): int
    {
        $vaultRoot = rtrim($vaultRoot, '/');
        $reportsDir = $vaultRoot . '/Reports';

        if (!is_dir($reportsDir)) {
            return 1;
        }

        $max = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($reportsDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            $meta = $this->reportParser->parse($file->getFilename());
            if ($meta !== null) {
                $max = max($max, $meta->reportNumber);
            }
        }

        return $max + 1;
    }
}
