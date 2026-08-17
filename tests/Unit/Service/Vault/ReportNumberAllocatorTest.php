<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vault;

use App\Service\Markdown\ReportFilenameParser;
use App\Service\Vault\ReportNumberAllocator;
use PHPUnit\Framework\TestCase;

final class ReportNumberAllocatorTest extends TestCase
{
    public function testAllocatesNextReportNumberFromVault(): void
    {
        $vaultRoot = sys_get_temp_dir() . '/rpgnotes-allocator-' . uniqid();
        mkdir($vaultRoot . '/Reports/1-10', 0777, true);
        file_put_contents($vaultRoot . '/Reports/1-10/Report-1 1.1.1367 Start.md', '# One');
        file_put_contents($vaultRoot . '/Reports/1-10/Report-3 2.1.1367 Next.md', '# Three');

        $allocator = new ReportNumberAllocator(new ReportFilenameParser());
        self::assertSame(4, $allocator->allocateNext($vaultRoot));
    }

    public function testStartsAtOneWhenReportsFolderMissing(): void
    {
        $vaultRoot = sys_get_temp_dir() . '/rpgnotes-empty-' . uniqid();
        mkdir($vaultRoot);

        $allocator = new ReportNumberAllocator(new ReportFilenameParser());
        self::assertSame(1, $allocator->allocateNext($vaultRoot));
    }
}
