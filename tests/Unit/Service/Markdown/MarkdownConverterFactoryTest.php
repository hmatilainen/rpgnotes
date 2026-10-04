<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\MarkdownConverterFactory;
use PHPUnit\Framework\TestCase;

final class MarkdownConverterFactoryTest extends TestCase
{
    public function testRendersGfmTables(): void
    {
        $html = (string) MarkdownConverterFactory::create()->convert("| | Status |\n|---|---|\n| **Myrbec** | Healed |\n");

        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<th>Status</th>', $html);
        self::assertStringContainsString('<td><strong>Myrbec</strong></td>', $html);
    }

    public function testStillEscapesRawHtmlAndUnsafeLinks(): void
    {
        $html = (string) MarkdownConverterFactory::create()->convert("<script>alert(1)</script>\n\n[x](javascript:alert(1))");

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
    }
}
