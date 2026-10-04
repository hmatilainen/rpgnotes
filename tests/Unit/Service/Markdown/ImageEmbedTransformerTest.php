<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\ImageEmbedTransformer;
use PHPUnit\Framework\TestCase;

final class ImageEmbedTransformerTest extends TestCase
{
    private ImageEmbedTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new ImageEmbedTransformer();
    }

    public function testConvertsImageEmbed(): void
    {
        self::assertSame('![rudi-wanted](/media/rudi-wanted.png)', $this->transformer->transform('![[rudi-wanted.png]]'));
    }

    public function testIgnoresSizeSuffixAndEncodesSpaces(): void
    {
        self::assertSame(
            '![myrbec-wanted 1](/media/myrbec-wanted%201.png)',
            $this->transformer->transform('![[myrbec-wanted 1.png|300]]')
        );
    }

    public function testUsesBasenameOfPathEmbeds(): void
    {
        self::assertSame('![a](/media/a.JPG)', $this->transformer->transform('![[Attachments/a.JPG]]'));
    }

    public function testLeavesNoteEmbedsAndPlainWikilinksAlone(): void
    {
        $input = "![[Some Note]] and [[img.png]]";

        self::assertSame($input, $this->transformer->transform($input));
    }
}
