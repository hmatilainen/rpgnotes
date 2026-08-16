<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vault;

use App\Service\Vault\HiddenPathMatcher;
use PHPUnit\Framework\TestCase;

final class HiddenPathMatcherTest extends TestCase
{
    private HiddenPathMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new HiddenPathMatcher();
    }

    public function testMatchesExactFilePath(): void
    {
        self::assertTrue($this->matcher->isHidden('Locations/Deerwater.md', ['Locations/Deerwater.md']));
    }

    public function testMatchesFileUnderHiddenTopLevelFolder(): void
    {
        self::assertTrue($this->matcher->isHidden('A - GM/Secrets.md', ['A - GM']));
    }

    public function testMatchesFileUnderHiddenNestedFolder(): void
    {
        self::assertTrue($this->matcher->isHidden(
            'Locations/Settlements/Silverymoon.md',
            ['Locations/Settlements']
        ));
    }

    public function testDoesNotMatchUnrelatedFile(): void
    {
        self::assertFalse($this->matcher->isHidden('People/Malekith.md', ['A - GM']));
    }

    public function testDoesNotFalsePositiveOnPathSegmentPrefix(): void
    {
        // "Locations2" must not be hidden by a "Locations" entry — this is
        // segment equality, not string prefix matching.
        self::assertFalse($this->matcher->isHidden('Locations2/Foo.md', ['Locations']));
    }

    public function testMatchIsCaseInsensitive(): void
    {
        self::assertTrue($this->matcher->isHidden('home.md', ['Home.md']));
    }
}
