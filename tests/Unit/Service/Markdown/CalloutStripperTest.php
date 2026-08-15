<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\CalloutStripper;
use PHPUnit\Framework\TestCase;

final class CalloutStripperTest extends TestCase
{
    private CalloutStripper $stripper;

    protected function setUp(): void
    {
        $this->stripper = new CalloutStripper();
    }

    public function testStripsCalloutBlockEntirely(): void
    {
        $input = "Before.\n\n> [!note] GM secret\n> Only the GM should see this.\n> Second line.\n\nAfter.";

        self::assertSame("Before.\n\n\nAfter.", $this->stripper->strip($input));
    }

    public function testPreservesPlainBlockquotes(): void
    {
        $input = "> Rudi, Nerinoa and Myrbec.\n> A regular in-fiction letter.";

        self::assertSame($input, $this->stripper->strip($input));
    }

    public function testStripsMultipleCalloutsInOneDocument(): void
    {
        $input = "> [!warning] First\n> line one\n\nMiddle text.\n\n> [!tip] Second\n> line two";

        self::assertSame("\n\nMiddle text.\n\n", $this->stripper->strip($input));
    }

    public function testCalloutAtEndOfDocument(): void
    {
        $input = "Content.\n\n> [!note] Trailing\n> last line";

        self::assertSame("Content.\n\n", $this->stripper->strip($input));
    }

    public function testDoesNotSynthesizeBlankLinesForMidDocumentCallouts(): void
    {
        // Regression test: callout with no blank lines before or after should not
        // have blank lines synthesized around it in the output.
        $input = "Before.\n> [!note] x\n> more\nAfter.";

        self::assertSame("Before.\nAfter.", $this->stripper->strip($input));
    }
}
