<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vault;

use App\Service\Vault\ReportFileBuilder;
use PHPUnit\Framework\TestCase;

final class ReportFileBuilderTest extends TestCase
{
    private ReportFileBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ReportFileBuilder();
    }

    public function testBuildVaultPathUsesReportRangeFolder(): void
    {
        $sessionDate = new \DateTimeImmutable('1367-08-16');
        $path = $this->builder->buildVaultPath(41, $sessionDate, 'Matka laaksoon');

        self::assertSame('Reports/41-50/Report-41 16.8.1367 Matka laaksoon.md', $path);
    }

    public function testBuildContentIncludesFrontmatterAndHeading(): void
    {
        $publishedAt = new \DateTimeImmutable('2026-08-16T20:00:00+01:00');
        $content = $this->builder->buildContent('The Beginning', 'Party met at Deerwater.', 'mikko', $publishedAt);

        self::assertStringContainsString('published_at:', $content);
        self::assertStringContainsString('author: mikko', $content);
        self::assertStringContainsString('## The Beginning', $content);
        self::assertStringContainsString('Party met at Deerwater.', $content);
    }
}
