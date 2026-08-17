<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\FrontmatterParser;
use PHPUnit\Framework\TestCase;

final class FrontmatterParserTest extends TestCase
{
    private FrontmatterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FrontmatterParser();
    }

    public function testParsePublishedAtFromFrontmatter(): void
    {
        $content = <<<'MD'
---
published_at: '2026-08-16T20:00:00+01:00'
author: mikko
---

## Title

Body
MD;

        $publishedAt = $this->parser->parsePublishedAt($content);

        self::assertNotNull($publishedAt);
        self::assertSame('2026-08-16T20:00:00+01:00', $publishedAt->format(\DateTimeInterface::ATOM));
    }

    public function testReturnsNullWhenFrontmatterMissing(): void
    {
        self::assertNull($this->parser->parsePublishedAt('# Hello'));
    }
}
