<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vault;

use App\Entity\HiddenPath;
use App\Entity\Note;
use App\Repository\HiddenPathRepository;
use App\Repository\NoteRepository;
use App\Service\Vault\HiddenPathMatcher;
use App\Service\Vault\NoteVisibilityService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class NoteVisibilityServiceTest extends TestCase
{
    public function testHideAddsHiddenPathAndFlagsNote(): void
    {
        $note = $this->makeNote('People/Malekith.md');

        $hiddenPaths = $this->createMock(HiddenPathRepository::class);
        $hiddenPaths->method('findOneBy')->willReturn(null);
        $hiddenPaths->method('findAllPaths')->willReturn([]);

        $notes = $this->createMock(NoteRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(HiddenPath::class));
        $em->expects(self::once())->method('flush');

        $service = new NoteVisibilityService($hiddenPaths, $notes, new HiddenPathMatcher(), $em);
        $service->hide($note);

        self::assertTrue($note->isHidden());
    }

    public function testUnhideReturnsFalseWhenFolderRuleStillApplies(): void
    {
        $note = $this->makeNote('A - GM/Secrets.md');
        $note->setHidden(true);

        $hiddenPaths = $this->createMock(HiddenPathRepository::class);
        $hiddenPaths->method('findOneBy')->willReturn(null);
        $hiddenPaths->method('findAllPaths')->willReturn(['A - GM']);

        $notes = $this->createMock(NoteRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $service = new NoteVisibilityService($hiddenPaths, $notes, new HiddenPathMatcher(), $em);

        self::assertFalse($service->unhide($note));
        self::assertTrue($note->isHidden());
    }

    public function testSyncHiddenFlagsFromRulesAppliesFolderRulesToIndexedNotes(): void
    {
        $visible = $this->makeNote('People/Malekith.md');
        $hidden = $this->makeNote('A - GM/Secrets.md');

        $hiddenPaths = $this->createMock(HiddenPathRepository::class);
        $hiddenPaths->method('findAllPaths')->willReturn(['A - GM']);

        $notes = $this->createMock(NoteRepository::class);
        $notes->method('findAll')->willReturn([$visible, $hidden]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $service = new NoteVisibilityService($hiddenPaths, $notes, new HiddenPathMatcher(), $em);
        $service->syncHiddenFlagsFromRules();

        self::assertFalse($visible->isHidden());
        self::assertTrue($hidden->isHidden());
    }

    private function makeNote(string $vaultPath): Note
    {
        $note = new Note();
        $note->setVaultPath($vaultPath);
        $note->setSlug('test/note');
        $note->setTitle('Test');
        $note->setTopLevelFolder('People');
        $note->setHtml('<p>x</p>');

        return $note;
    }
}
