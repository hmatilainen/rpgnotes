<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\ReportFilenameParser;
use PHPUnit\Framework\TestCase;

final class ReportFilenameParserTest extends TestCase
{
    private ReportFilenameParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ReportFilenameParser();
    }

    public function testParsesValidReportFilename(): void
    {
        $meta = $this->parser->parse('Report-41 20.2.1367 Matka Brokenstonen laaksoon.md');

        self::assertNotNull($meta);
        self::assertSame(41, $meta->reportNumber);
        self::assertNotNull($meta->sessionDate);
        self::assertSame('20.02.1367', $meta->sessionDate->format('d.m.Y'));
        self::assertSame('Matka Brokenstonen laaksoon', $meta->title);
    }

    public function testReturnsNullForNonReportFilename(): void
    {
        self::assertNull($this->parser->parse('Tähän mennessä tapahtunutta.md'));
    }

    public function testReturnsNullForUnrelatedFilename(): void
    {
        self::assertNull($this->parser->parse('Deerwater.md'));
    }
}
