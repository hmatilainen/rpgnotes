<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SessionReport;

use App\Service\SessionReport\InGameDateParser;
use PHPUnit\Framework\TestCase;

final class InGameDateParserTest extends TestCase
{
    private InGameDateParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InGameDateParser();
    }

    public function testParsesInGameDate(): void
    {
        $parsed = $this->parser->parse('16.8.1367');

        self::assertNotNull($parsed);
        self::assertSame('1367-08-16', $parsed->format('Y-m-d'));
    }

    public function testRejectsInvalidDate(): void
    {
        self::assertNull($this->parser->parse('not-a-date'));
        self::assertNull($this->parser->parse('32.1.1367'));
    }
}
