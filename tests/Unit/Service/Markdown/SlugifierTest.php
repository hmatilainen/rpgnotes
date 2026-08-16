<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\Slugifier;
use PHPUnit\Framework\TestCase;

final class SlugifierTest extends TestCase
{
    private Slugifier $slugifier;

    protected function setUp(): void
    {
        $this->slugifier = new Slugifier();
    }

    public function testSlugifiesSimplePath(): void
    {
        self::assertSame('locations/deerwater', $this->slugifier->slugifyPath('Locations/Deerwater.md'));
    }

    public function testSlugifiesPathWithSpacesAndPunctuation(): void
    {
        self::assertSame(
            'reports/41-50/report-41-20-2-1367-matka-brokenstonen-laaksoon',
            $this->slugifier->slugifyPath('Reports/41-50/Report-41 20.2.1367 Matka Brokenstonen laaksoon.md')
        );
    }

    public function testTransliteratesFinnishDiacritics(): void
    {
        $result = $this->slugifier->slugifyPath('Reports/Tähän mennessä tapahtunutta.md');

        self::assertStringNotContainsString('ä', $result);
        self::assertSame('reports/tahan-mennessa-tapahtunutta', $result);
    }

    public function testFallsBackToStableSlugWhenSegmentHasNoSlugifiableCharacters(): void
    {
        $result = $this->slugifier->slugifyPath('People/🎲.md');

        self::assertMatchesRegularExpression('#^people/untitled-[0-9a-f]{8}$#', $result);
    }

    public function testFallbackSlugIsStableForTheSameInput(): void
    {
        self::assertSame(
            $this->slugifier->slugifyPath('People/🎲.md'),
            $this->slugifier->slugifyPath('People/🎲.md')
        );
    }
}
