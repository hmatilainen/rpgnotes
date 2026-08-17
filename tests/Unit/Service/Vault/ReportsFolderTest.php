<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vault;

use App\Service\Vault\ReportsFolder;
use PHPUnit\Framework\TestCase;

final class ReportsFolderTest extends TestCase
{
    public function testAcceptsSimpleFolderName(): void
    {
        $folder = new ReportsFolder('Reports');

        self::assertSame('Reports', $folder->name);
    }

    public function testTrimsSlashes(): void
    {
        $folder = new ReportsFolder('/Session Notes/');

        self::assertSame('Session Notes', $folder->name);
    }

    public function testRejectsNestedPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReportsFolder('Reports/archive');
    }
}
