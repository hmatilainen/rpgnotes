<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vault;

use App\Entity\Note;
use App\Repository\NoteRepository;
use App\Service\Vault\HiddenPathNormalizer;
use PHPUnit\Framework\TestCase;

final class HiddenPathNormalizerTest extends TestCase
{
    public function testResolvesNoteSlugToVaultPath(): void
    {
        $note = new Note();
        $note->setVaultPath('People/Aidan - Watchful Order\'s Night Watch Agent.md');
        $note->setSlug('people/aidan-watchful-order-s-night-watch-agent');

        $notes = $this->createMock(NoteRepository::class);
        $notes->method('findOneBySlug')->with('people/aidan-watchful-order-s-night-watch-agent')->willReturn($note);
        $notes->method('findOneByVaultPath')->willReturn(null);

        $normalizer = new HiddenPathNormalizer($notes);

        self::assertSame(
            'People/Aidan - Watchful Order\'s Night Watch Agent.md',
            $normalizer->normalize('people/aidan-watchful-order-s-night-watch-agent'),
        );
    }

    public function testResolvesNotesUrlToVaultPath(): void
    {
        $note = new Note();
        $note->setVaultPath('People/Aidan - Watchful Order\'s Night Watch Agent.md');
        $note->setSlug('people/aidan-watchful-order-s-night-watch-agent');

        $notes = $this->createMock(NoteRepository::class);
        $notes->method('findOneBySlug')->with('people/aidan-watchful-order-s-night-watch-agent')->willReturn($note);
        $notes->method('findOneByVaultPath')->willReturn(null);

        $normalizer = new HiddenPathNormalizer($notes);

        self::assertSame(
            'People/Aidan - Watchful Order\'s Night Watch Agent.md',
            $normalizer->normalize('/notes/people/aidan-watchful-order-s-night-watch-agent'),
        );
    }

    public function testKeepsFolderPathWhenNoNoteMatches(): void
    {
        $notes = $this->createMock(NoteRepository::class);
        $notes->method('findOneBySlug')->willReturn(null);
        $notes->method('findOneByVaultPath')->willReturn(null);

        $normalizer = new HiddenPathNormalizer($notes);

        self::assertSame('A - GM', $normalizer->normalize('A - GM'));
    }
}
