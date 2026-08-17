<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\NoteDraft;
use App\Service\Markdown\WikilinkExtractor;
use App\Service\Markdown\WikilinkIndex;
use PHPUnit\Framework\TestCase;

final class WikilinkExtractorTest extends TestCase
{
    private WikilinkExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new WikilinkExtractor();
    }

    public function testResolvesVisibleWikilinks(): void
    {
        $index = new WikilinkIndex([
            new NoteDraft(
                vaultPath: 'Locations/Deerwater.md',
                title: 'Deerwater',
                slug: 'locations/deerwater',
                topLevelFolder: 'Locations',
                strippedContent: '',
                reportNumber: null,
                sessionDate: null,
                publishedAt: null,
                hidden: false,
            ),
        ]);

        $targets = $this->extractor->extractTargets('We visited [[Locations/Deerwater]] today.');
        $resolved = $this->extractor->resolveVisible($targets, $index);

        self::assertCount(1, $resolved);
        self::assertSame('locations/deerwater', $resolved[0]['slug']);
    }
}
