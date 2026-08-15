<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\ImagePlaceholderStripper;
use PHPUnit\Framework\TestCase;

final class ImagePlaceholderStripperTest extends TestCase
{
    private ImagePlaceholderStripper $stripper;

    protected function setUp(): void
    {
        $this->stripper = new ImagePlaceholderStripper();
    }

    public function testRemovesImagePlaceholder(): void
    {
        $input = "Cainin kartta [img:153388]\n\nMalekith tutki karttaa.";

        self::assertSame("Cainin kartta \n\nMalekith tutki karttaa.", $this->stripper->strip($input));
    }

    public function testRemovesMultiplePlaceholders(): void
    {
        $input = "[img:1] text [img:22222]";

        self::assertSame(" text ", $this->stripper->strip($input));
    }

    public function testLeavesContentWithoutPlaceholdersUnchanged(): void
    {
        $input = "No placeholders here.";

        self::assertSame($input, $this->stripper->strip($input));
    }
}
