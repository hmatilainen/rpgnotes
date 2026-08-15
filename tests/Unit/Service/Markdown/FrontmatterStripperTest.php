<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\FrontmatterStripper;
use PHPUnit\Framework\TestCase;

final class FrontmatterStripperTest extends TestCase
{
    private FrontmatterStripper $stripper;

    protected function setUp(): void
    {
        $this->stripper = new FrontmatterStripper();
    }

    public function testStripsLeadingFrontmatter(): void
    {
        $input = "---\ntype: plot\n---\n\n# Heading\n\nBody text.";
        $result = $this->stripper->strip($input);

        self::assertSame("\n# Heading\n\nBody text.", $result);
    }

    public function testLeavesContentWithoutFrontmatterUnchanged(): void
    {
        $input = "# Heading\n\nBody text.";

        self::assertSame($input, $this->stripper->strip($input));
    }

    public function testDoesNotStripDashesThatAreNotLeadingFrontmatter(): void
    {
        $input = "# Heading\n\n---\n\nHorizontal rule below, not frontmatter.";

        self::assertSame($input, $this->stripper->strip($input));
    }
}
