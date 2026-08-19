<?php

declare(strict_types=1);

namespace App\Service\SessionReport;

use App\Entity\SessionNoteDraft;
use App\Entity\ShareToken;
use App\Repository\NoteRepository;
use App\Repository\ShareTokenRepository;
use App\Service\Vault\GitPublishService;
use App\Service\Vault\NoteIndexer;
use App\Service\Vault\ReportFileBuilder;
use App\Service\Vault\ReportNumberAllocator;
use Doctrine\ORM\EntityManagerInterface;

final class SessionNotePublisher
{
    public function __construct(
        private readonly GitPublishService $gitPublish,
        private readonly ReportNumberAllocator $reportNumbers,
        private readonly ReportFileBuilder $reportFiles,
        private readonly NoteIndexer $noteIndexer,
        private readonly NoteRepository $notes,
        private readonly ShareTokenRepository $shareTokens,
        private readonly EntityManagerInterface $em,
        private readonly string $vaultPath,
    ) {
    }

    public function publish(SessionNoteDraft $draft): ShareToken
    {
        $author = $draft->getAuthor();
        $username = $author->getUsername();

        if ($username === null) {
            throw new \RuntimeException('Draft author has no username.');
        }

        $this->gitPublish->syncToRemote();

        $publishedAt = new \DateTimeImmutable();
        $reportNumber = $this->reportNumbers->allocateNext($this->vaultPath);
        $vaultPath = $this->reportFiles->buildVaultPath(
            $reportNumber,
            $draft->getSessionDate(),
            $draft->getTitle(),
        );
        $content = $this->reportFiles->buildContent(
            $draft->getTitle(),
            $draft->getBody(),
            $username,
            $publishedAt,
        );

        $commitMessage = sprintf('Add session report #%d by %s', $reportNumber, $username);

        $this->gitPublish->addAndPush($vaultPath, $content, $commitMessage);
        $this->noteIndexer->index($this->vaultPath);

        $note = $this->notes->findOneByVaultPath($vaultPath);
        if ($note === null) {
            throw new \RuntimeException(sprintf('Published note not found after index: %s', $vaultPath));
        }

        $token = $this->shareTokens->findOneBy(['note' => $note]);
        if ($token === null) {
            $token = new ShareToken($note, bin2hex(random_bytes(32)));
            $this->em->persist($token);
        }

        $this->em->remove($draft);
        $this->em->flush();

        return $token;
    }
}
