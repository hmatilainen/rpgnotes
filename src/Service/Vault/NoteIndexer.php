<?php

declare(strict_types=1);

namespace App\Service\Vault;

use App\Entity\Note;
use App\Repository\HiddenPathRepository;
use App\Repository\NoteRepository;
use App\Service\Markdown\CalloutStripper;
use App\Service\Markdown\FrontmatterParser;
use App\Service\Markdown\FrontmatterStripper;
use App\Service\Markdown\ImagePlaceholderStripper;
use App\Service\Markdown\NoteDraft;
use App\Service\Markdown\ReportFilenameParser;
use App\Service\Markdown\Slugifier;
use App\Service\Markdown\WikilinkExtractor;
use App\Service\Markdown\WikilinkIndex;
use App\Service\Markdown\WikilinkTransformer;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\ConverterInterface;

class NoteIndexer
{
    /**
     * @param string[] $excludedTopLevelDirs
     */
    public function __construct(
        private readonly VaultFileScanner $scanner,
        private readonly FrontmatterParser $frontmatterParser,
        private readonly FrontmatterStripper $frontmatterStripper,
        private readonly CalloutStripper $calloutStripper,
        private readonly ImagePlaceholderStripper $imageStripper,
        private readonly WikilinkTransformer $wikilinkTransformer,
        private readonly WikilinkExtractor $wikilinkExtractor,
        private readonly Slugifier $slugifier,
        private readonly ReportFilenameParser $reportParser,
        private readonly ConverterInterface $markdownConverter,
        private readonly NoteRepository $notes,
        private readonly HiddenPathRepository $hiddenPaths,
        private readonly HiddenPathMatcher $hiddenPathMatcher,
        private readonly EntityManagerInterface $em,
        private readonly array $excludedTopLevelDirs,
    ) {
    }

    public function index(string $vaultRoot): IndexResult
    {
        $vaultRoot = rtrim($vaultRoot, '/');
        $files = $this->scanner->scan($vaultRoot, $this->excludedTopLevelDirs);
        $hiddenPaths = $this->hiddenPaths->findAllPaths();

        $drafts = array_map(fn (string $path) => $this->buildDraft($vaultRoot, $path, $hiddenPaths), $files);
        $this->resolveSlugCollisions($drafts);

        // Hidden notes ARE indexed (so an admin can browse them) but are
        // never added to the WikilinkIndex, so a wikilink pointing at one
        // renders as plain text for every viewer, admin included — see the
        // spec's "Admin link resolution" decision.
        $visibleDrafts = array_values(array_filter($drafts, static fn (NoteDraft $draft) => !$draft->hidden));
        $index = new WikilinkIndex($visibleDrafts);
        $currentPaths = [];

        foreach ($drafts as $draft) {
            $wikilinks = $draft->hidden
                ? []
                : $this->wikilinkExtractor->resolveVisible(
                    $this->wikilinkExtractor->extractTargets($draft->strippedContent),
                    $index,
                );

            $withLinks = $this->wikilinkTransformer->transform($draft->strippedContent, $index);
            $html = (string) $this->markdownConverter->convert($withLinks);

            $note = $this->notes->findOneByVaultPath($draft->vaultPath) ?? new Note();
            $note->setVaultPath($draft->vaultPath);
            $note->setSlug($draft->slug);
            $note->setTitle($draft->title);
            $note->setTopLevelFolder($draft->topLevelFolder);
            $note->setHtml($html);
            $note->setBodyMarkdown($draft->strippedContent);
            $note->setWikilinks($wikilinks);
            $note->setReportNumber($draft->reportNumber);
            $note->setSessionDate($draft->sessionDate);
            $note->setPublishedAt($draft->publishedAt);
            $note->setHidden($draft->hidden);
            $note->setUpdatedAt(new \DateTimeImmutable());

            $this->em->persist($note);
            $currentPaths[] = $draft->vaultPath;
        }

        $stale = $this->notes->findByVaultPathNotIn($currentPaths);
        foreach ($stale as $staleNote) {
            $this->em->remove($staleNote);
        }

        $this->em->flush();

        $this->em->getConnection()->executeStatement(
            'UPDATE notes SET search_vector = to_tsvector(\'simple\', coalesce(title, \'\') || \' \' || coalesce(body_markdown, \'\'))'
        );

        return new IndexResult(updated: \count($drafts), deleted: \count($stale));
    }

    /**
     * Disambiguates slugs that collide across drafts. Note.slug has a UNIQUE
     * database constraint, so two vault files that slugify to the same value
     * (e.g. filenames differing only in stripped characters) would otherwise
     * make every subsequent sync fail identically. Drafts are processed in a
     * deterministic order (sorted by vault path) and any slug already claimed
     * gets a numeric suffix (-2, -3, ...) appended until it is unique.
     *
     * @param NoteDraft[] $drafts
     */
    private function resolveSlugCollisions(array $drafts): void
    {
        $ordered = $drafts;
        usort($ordered, static fn (NoteDraft $a, NoteDraft $b) => strcmp($a->vaultPath, $b->vaultPath));

        $usedSlugs = [];
        foreach ($ordered as $draft) {
            $baseSlug = $draft->slug;
            $candidate = $baseSlug;
            $suffix = 2;
            while (isset($usedSlugs[$candidate])) {
                $candidate = sprintf('%s-%d', $baseSlug, $suffix);
                ++$suffix;
            }

            $draft->slug = $candidate;
            $usedSlugs[$candidate] = true;
        }
    }

    /**
     * @param string[] $hiddenPaths
     */
    private function buildDraft(string $vaultRoot, string $absolutePath, array $hiddenPaths): NoteDraft
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
        $publishedAt = $this->frontmatterParser->parsePublishedAt($raw);

        return new NoteDraft(
            vaultPath: $vaultPath,
            title: $title,
            slug: $this->slugifier->slugifyPath($vaultPath),
            topLevelFolder: $topLevelFolder,
            strippedContent: $stripped,
            reportNumber: $reportMeta?->reportNumber,
            sessionDate: $reportMeta?->sessionDate,
            publishedAt: $publishedAt,
            hidden: $this->hiddenPathMatcher->isHidden($vaultPath, $hiddenPaths),
        );
    }
}
