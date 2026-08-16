<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vault;

use App\Service\Vault\VaultFileScanner;
use PHPUnit\Framework\TestCase;

final class VaultFileScannerTest extends TestCase
{
    private VaultFileScanner $scanner;
    private string $vaultRoot;

    protected function setUp(): void
    {
        $this->scanner = new VaultFileScanner();
        $this->vaultRoot = \dirname(__DIR__, 3) . '/Fixtures/vault';
    }

    public function testIncludesContentFilesAndExcludesConfiguredDirs(): void
    {
        $results = $this->scanner->scan($this->vaultRoot, ['.obsidian', 'docs']);

        $relative = array_map(
            fn (string $path) => str_replace($this->vaultRoot . '/', '', $path),
            $results
        );

        self::assertContains('People/Malekith.md', $relative);
        self::assertContains('Locations/Deerwater.md', $relative);
        self::assertContains('Reports/1-10/Report-1 1.1.1367 The Beginning.md', $relative);
        self::assertContains('Reports/Tähän mennessä tapahtunutta.md', $relative);
        // Hidden-folder filtering moved to NoteIndexer (via HiddenPathMatcher)
        // in Phase 2 — the scanner itself no longer excludes it.
        self::assertContains('A - GM/Secrets.md', $relative);

        self::assertStringNotContainsString('.obsidian', implode(',', $relative));
        self::assertStringNotContainsString('docs/ignored.md', implode(',', $relative));
    }

    public function testResultsAreSorted(): void
    {
        $results = $this->scanner->scan($this->vaultRoot, ['.obsidian', 'docs']);
        $sorted = $results;
        sort($sorted);

        self::assertSame($sorted, $results);
    }
}
