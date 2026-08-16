<?php

declare(strict_types=1);

namespace App\Service\Vault;

use App\Entity\Note;
use App\Repository\NoteRepository;
use App\Service\Markdown\CalloutStripper;
use App\Service\Markdown\FrontmatterStripper;
use App\Service\Markdown\ImagePlaceholderStripper;
use App\Service\Markdown\NoteDraft;
use App\Service\Markdown\ReportFilenameParser;
use App\Service\Markdown\Slugifier;
use App\Service\Markdown\WikilinkIndex;
use App\Service\Markdown\WikilinkTransformer;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\ConverterInterface;

final class NoteIndexer
{
    /**
     * @param string[] $excludedTopLevelDirs
     * @param string[] $hiddenTopLevelDirs
     */
    public function __construct(
        private readonly VaultFileScanner $scanner,
        private readonly FrontmatterStripper $frontmatterStripper,
        private readonly CalloutStripper $calloutStripper,
        private readonly ImagePlaceholderStripper $imageStripper,
        private readonly WikilinkTransformer $wikilinkTransformer,
        private readonly Slugifier $slugifier,
        private readonly ReportFilenameParser $reportParser,
        private readonly ConverterInterface $markdownConverter,
        private readonly NoteRepository $notes,
        private readonly EntityManagerInterface $em,
        private readonly array $excludedTopLevelDirs,
        private readonly array $hiddenTopLevelDirs,
    ) {
    }

    public function index(string $vaultRoot): IndexResult
    {
        $vaultRoot = rtrim($vaultRoot, '/');
        $files = $this->scanner->scan($vaultRoot, $this->excludedTopLevelDirs, $this->hiddenTopLevelDirs);

        $drafts = array_map(fn (string $path) => $this->buildDraft($vaultRoot, $path), $files);
        $index = new WikilinkIndex($drafts);
        $currentPaths = [];

        foreach ($drafts as $draft) {
            $withLinks = $this->wikilinkTransformer->transform($draft->strippedContent, $index);
            $html = (string) $this->markdownConverter->convert($withLinks);

            $note = $this->notes->findOneByVaultPath($draft->vaultPath) ?? new Note();
            $note->setVaultPath($draft->vaultPath);
            $note->setSlug($draft->slug);
            $note->setTitle($draft->title);
            $note->setTopLevelFolder($draft->topLevelFolder);
            $note->setHtml($html);
            $note->setReportNumber($draft->reportNumber);
            $note->setSessionDate($draft->sessionDate);
            $note->setUpdatedAt(new \DateTimeImmutable());

            $this->em->persist($note);
            $currentPaths[] = $draft->vaultPath;
        }

        $stale = $this->notes->findByVaultPathNotIn($currentPaths);
        foreach ($stale as $staleNote) {
            $this->em->remove($staleNote);
        }

        $this->em->flush();

        return new IndexResult(updated: \count($drafts), deleted: \count($stale));
    }

    private function buildDraft(string $vaultRoot, string $absolutePath): NoteDraft
    {
        $vaultPath = ltrim(str_replace($vaultRoot, '', $absolutePath), '/');
        $filename = basename($vaultPath);
        $title = preg_replace('/\.md$/i', '', $filename) ?? $filename;
        $topLevelFolder = explode('/', $vaultPath)[0];

        $raw = file_get_contents($absolutePath);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Unable to read vault file: %s', $absolutePath));
        }

        $stripped = $this->imageStripper->strip(
            $this->calloutStripper->strip(
                $this->frontmatterStripper->strip($raw)
            )
        );

        $reportMeta = $this->reportParser->parse($filename);

        return new NoteDraft(
            vaultPath: $vaultPath,
            title: $title,
            slug: $this->slugifier->slugifyPath($vaultPath),
            topLevelFolder: $topLevelFolder,
            strippedContent: $stripped,
            reportNumber: $reportMeta?->reportNumber,
            sessionDate: $reportMeta?->sessionDate,
        );
    }
}
