<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vault;

use App\Service\Vault\ReportFileBuilder;
use App\Service\Vault\ReportsFolder;
use PHPUnit\Framework\TestCase;

final class ReportFileBuilderTest extends TestCase
{
    public function testBuildVaultPathUsesReportRangeFolder(): void
    {
        $builder = new ReportFileBuilder(new ReportsFolder('Reports'));
        $sessionDate = new \DateTimeImmutable('1367-08-16');
        $path = $builder->buildVaultPath(41, $sessionDate, 'Matka laaksoon');

        self::assertSame('Reports/41-50/Report-41 16.8.1367 Matka laaksoon.md', $path);
    }

    public function testBuildVaultPathUsesConfiguredReportsFolder(): void
    {
        $builder = new ReportFileBuilder(new ReportsFolder('Session Notes'));
        $sessionDate = new \DateTimeImmutable('1367-08-16');
        $path = $builder->buildVaultPath(3, $sessionDate, 'Opening scene');

        self::assertSame('Session Notes/1-10/Report-3 16.8.1367 Opening scene.md', $path);
    }

    public function testBuildContentIncludesFrontmatterAndHeading(): void
    {
        $builder = new ReportFileBuilder(new ReportsFolder('Reports'));
        $publishedAt = new \DateTimeImmutable('2026-08-16T20:00:00+01:00');
        $content = $builder->buildContent('The Beginning', 'Party met at Deerwater.', 'mikko', $publishedAt);

        self::assertStringContainsString('published_at:', $content);
        self::assertStringContainsString('author: mikko', $content);
        self::assertStringContainsString('## The Beginning', $content);
        self::assertStringContainsString('Party met at Deerwater.', $content);
    }
}
