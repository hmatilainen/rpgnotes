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

    public function testExtractsReportNumberWithNullDateForFinnishWeekdayMonthNames(): void
    {
        $meta = $this->parser->parse('Report-1 Tiistai 28. lokakuuta 1366.md');

        self::assertNotNull($meta);
        self::assertSame(1, $meta->reportNumber);
        self::assertNull($meta->sessionDate);
        self::assertSame('Tiistai 28. lokakuuta 1366', $meta->title);
    }

    public function testExtractsReportNumberWithNullDateWhenMonthNameHasNoLeadingSpace(): void
    {
        $meta = $this->parser->parse('Report-2 Torstai 30.lokakuuta 1366.md');

        self::assertNotNull($meta);
        self::assertSame(2, $meta->reportNumber);
        self::assertNull($meta->sessionDate);
    }

    public function testExtractsReportNumberWithNullDateWhenNoYearIsPresent(): void
    {
        $meta = $this->parser->parse('Report-12 Maanantai 24. marraskuuta.md');

        self::assertNotNull($meta);
        self::assertSame(12, $meta->reportNumber);
        self::assertNull($meta->sessionDate);
    }
}
