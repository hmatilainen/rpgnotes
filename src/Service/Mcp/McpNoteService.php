<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Entity\Note;
use App\Entity\User;
use App\Repository\NoteRepository;
use App\Repository\ShareTokenRepository;
use App\Service\Sidebar\SidebarBuilder;
use App\Service\Sidebar\SidebarNode;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class McpNoteService
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly ShareTokenRepository $shareTokens,
        private readonly SidebarBuilder $sidebar,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSiteOverview(User $user): array
    {
        $newest = $this->notes->findNewestReport(includeHidden: false);
        $folders = $this->notes->findDistinctTopLevelFolders(includeHidden: false);

        return [
            'campaign' => 'Forgotten Realms',
            'site_url' => $this->absoluteUrl('front_page'),
            'folders' => $folders,
            'newest_report' => $newest !== null ? $this->summarizeReport($newest) : null,
            'hint' => 'Session reports live under Reports/. Reference notes use folders like People/, Locations/. Wikilinks use [[Folder/Name]] syntax.',
        ];
    }

    /**
     * @return array{page: int, per_page: int, total: int, reports: list<array<string, mixed>>}
     */
    public function listSessionReports(User $user, int $page, int $perPage): array
    {
        $reports = $this->notes->findReportsPaginated($page, $perPage, includeHidden: false, skipFeaturedReport: false);

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $this->notes->countReports(includeHidden: false),
            'reports' => array_map(fn (Note $note) => $this->summarizeReport($note), $reports),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSessionReport(User $user, ?int $reportNumber, ?string $slug): ?array
    {
        $note = null;

        if ($reportNumber !== null) {
            $note = $this->notes->findOneBy(['reportNumber' => $reportNumber]);
        } elseif ($slug !== null) {
            $note = $this->notes->findOneBySlug($slug);
        }

        if ($note === null || $note->getReportNumber() === null || $note->isHidden()) {
            return null;
        }

        return $this->formatNote($note);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getNote(User $user, ?string $slug, ?string $vaultPath): ?array
    {
        $note = null;

        if ($slug !== null) {
            $note = $this->notes->findOneBySlug($slug);
        } elseif ($vaultPath !== null) {
            $normalized = str_ends_with(mb_strtolower($vaultPath), '.md') ? $vaultPath : $vaultPath . '.md';
            $note = $this->notes->findOneByVaultPath($normalized);
        }

        if ($note === null || $note->isHidden()) {
            return null;
        }

        return $this->formatNote($note);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchNotes(User $user, string $query, int $limit, ?string $folder): array
    {
        $results = $this->notes->searchByText(
            $query,
            includeHidden: false,
            limit: max(1, min($limit, 50)),
            folder: $folder,
        );

        return array_map(fn (Note $note) => $this->summarizeNote($note), $results);
    }

    /**
     * @return array<string, mixed>
     */
    public function browseVault(User $user, ?string $folder): array
    {
        $root = $this->sidebar->build(includeHidden: false);

        if ($folder === null || $folder === '') {
            return $this->serializeBrowseNode($root, '');
        }

        $node = $this->findFolderNode($root, $folder);
        if ($node === null) {
            return [
                'name' => $folder,
                'path' => trim($folder, '/'),
                'folders' => [],
                'notes' => [],
                'error' => 'Folder not found. Use browse_vault at root to list top-level folders.',
            ];
        }

        return $this->serializeBrowseNode($node, trim($folder, '/'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formatNote(Note $note): array
    {
        $data = $this->summarizeNote($note);
        $data['markdown'] = $note->getBodyMarkdown();
        $data['wikilinks'] = $note->getWikilinks();

        if ($note->getReportNumber() !== null) {
            $data['report_number'] = $note->getReportNumber();
            $prev = $this->notes->findPreviousReport($note->getReportNumber(), includeHidden: false);
            $next = $this->notes->findNextReport($note->getReportNumber(), includeHidden: false);
            $data['prev_report'] = $prev?->getReportNumber();
            $data['next_report'] = $next?->getReportNumber();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeReport(Note $note): array
    {
        $data = $this->summarizeNote($note);
        $data['report_number'] = $note->getReportNumber();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeNote(Note $note): array
    {
        $shareToken = $this->shareTokens->findOneBy(['note' => $note]);

        return [
            'slug' => $note->getSlug(),
            'vault_path' => $note->getVaultPath(),
            'title' => $note->getTitle(),
            'top_level_folder' => $note->getTopLevelFolder(),
            'session_date' => $note->getSessionDate()?->format('Y-m-d'),
            'published_at' => $note->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'url' => $this->absoluteUrl('note_show', ['slug' => $note->getSlug()]),
            'share_url' => $shareToken !== null
                ? $this->absoluteUrl('share_show', ['token' => $shareToken->getToken()])
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBrowseNode(SidebarNode $node, string $path): array
    {
        $folders = [];
        foreach ($node->getFolders() as $name => $child) {
            $childPath = $path === '' ? $name : $path.'/'.$name;
            $folders[] = [
                'name' => $name,
                'path' => $childPath,
                'note_count' => $this->countNotesInSubtree($child),
            ];
        }

        $notes = [];
        foreach ($node->getNotes() as $note) {
            $notes[] = [
                'title' => $note->getTitle(),
                'slug' => $note->getSlug(),
                'vault_path' => $note->getVaultPath(),
            ];
        }

        return [
            'name' => $path === '' ? 'vault root' : basename($path),
            'path' => $path,
            'folders' => $folders,
            'notes' => $notes,
        ];
    }

    private function countNotesInSubtree(SidebarNode $node): int
    {
        $count = \count($node->getNotes());
        foreach ($node->getFolders() as $child) {
            $count += $this->countNotesInSubtree($child);
        }

        return $count;
    }

    private function findFolderNode(SidebarNode $root, string $folder): ?SidebarNode
    {
        $segments = explode('/', trim($folder, '/'));
        $cursor = $root;

        foreach ($segments as $segment) {
            $children = $cursor->getFolders();
            if (!isset($children[$segment])) {
                return null;
            }
            $cursor = $children[$segment];
        }

        return $cursor;
    }

    private function absoluteUrl(string $route, array $params = []): string
    {
        return $this->urlGenerator->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
