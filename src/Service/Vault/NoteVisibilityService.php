<?php

declare(strict_types=1);

namespace App\Service\Vault;

use App\Entity\HiddenPath;
use App\Entity\Note;
use App\Repository\HiddenPathRepository;
use Doctrine\ORM\EntityManagerInterface;

final class NoteVisibilityService
{
    public function __construct(
        private readonly HiddenPathRepository $hiddenPaths,
        private readonly HiddenPathMatcher $hiddenPathMatcher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function hide(Note $note): void
    {
        $vaultPath = $note->getVaultPath();

        if ($this->hiddenPaths->findOneBy(['path' => $vaultPath]) === null) {
            $hiddenPath = new HiddenPath();
            $hiddenPath->setPath($vaultPath);
            $this->em->persist($hiddenPath);
        }

        $note->setHidden(true);
        $this->em->flush();
    }

    /**
     * Removes a direct hidden-path rule for this file. Returns false when the note
     * stays hidden because a parent folder is still on the hidden-path list.
     */
    public function unhide(Note $note): bool
    {
        $vaultPath = $note->getVaultPath();
        $hiddenPath = $this->hiddenPaths->findOneBy(['path' => $vaultPath]);

        if ($hiddenPath !== null) {
            $this->em->remove($hiddenPath);
            $this->em->flush();
        }

        if ($this->hiddenPathMatcher->isHidden($vaultPath, $this->hiddenPaths->findAllPaths())) {
            $note->setHidden(true);
            $this->em->flush();

            return false;
        }

        $note->setHidden(false);
        $this->em->flush();

        return true;
    }

    public function isHiddenByFolderRule(Note $note): bool
    {
        if (!$note->isHidden()) {
            return false;
        }

        if ($this->hiddenPaths->findOneBy(['path' => $note->getVaultPath()]) !== null) {
            return false;
        }

        return $this->hiddenPathMatcher->isHidden($note->getVaultPath(), $this->hiddenPaths->findAllPaths());
    }
}
