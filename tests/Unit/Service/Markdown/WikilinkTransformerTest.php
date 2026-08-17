<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\NoteDraft;
use App\Service\Markdown\WikilinkIndex;
use App\Service\Markdown\WikilinkTransformer;
use PHPUnit\Framework\TestCase;

final class WikilinkTransformerTest extends TestCase
{
    private WikilinkTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new WikilinkTransformer();
    }

    private function draft(string $vaultPath, string $slug): NoteDraft
    {
        return new NoteDraft(
            vaultPath: $vaultPath,
            title: basename($vaultPath, '.md'),
            slug: $slug,
            topLevelFolder: explode('/', $vaultPath)[0],
            strippedContent: '',
            reportNumber: null,
            sessionDate: null,
            publishedAt: null,
            hidden: false,
        );
    }

    public function testResolvesExactPathMatch(): void
    {
        $index = new WikilinkIndex([$this->draft('Locations/Deerwater.md', 'locations/deerwater')]);
        $result = $this->transformer->transform('Seurue saapui [[Locations/Deerwater]]iin.', $index);

        self::assertSame('Seurue saapui [Deerwater](/notes/locations/deerwater)iin.', $result);
    }

    public function testResolvesUniqueFilenameOnlyMatch(): void
    {
        $index = new WikilinkIndex([$this->draft('People/Malekith.md', 'people/malekith')]);
        $result = $this->transformer->transform('[[Malekith]] arrives.', $index);

        self::assertSame('[Malekith](/notes/people/malekith) arrives.', $result);
    }

    public function testUsesDisplayTextWhenGiven(): void
    {
        $index = new WikilinkIndex([$this->draft('Locations/Settlements/Silverymoon.md', 'locations/settlements/silverymoon')]);
        $result = $this->transformer->transform('Kohti [[Locations/Settlements/Silverymoon|Silverymoon]]ia.', $index);

        self::assertSame('Kohti [Silverymoon](/notes/locations/settlements/silverymoon)ia.', $result);
    }

    public function testAmbiguousFilenameResolvesToStableFirstMatch(): void
    {
        $index = new WikilinkIndex([
            $this->draft('Locations/Zeta/Runa.md', 'locations/zeta/runa'),
            $this->draft('People/Runa.md', 'people/runa'),
        ]);
        $result = $this->transformer->transform('[[Runa]]', $index);

        self::assertSame('[Runa](/notes/locations/zeta/runa)', $result);
    }

    public function testUnresolvableTargetRendersAsPlainText(): void
    {
        $index = new WikilinkIndex([]);
        $result = $this->transformer->transform('[[Nonexistent Page]]', $index);

        self::assertSame('Nonexistent Page', $result);
    }

    public function testHiddenTargetNotIncludedInIndexRendersAsPlainText(): void
    {
        // A - GM notes are never added to the WikilinkIndex (excluded during scanning),
        // so a link to one behaves identically to an unresolvable link.
        $index = new WikilinkIndex([$this->draft('People/Malekith.md', 'people/malekith')]);
        $result = $this->transformer->transform('[[A - GM/Secrets]]', $index);

        self::assertSame('Secrets', $result);
    }
}
