<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\User;
use App\Service\Mcp\McpNoteService;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Bundle\SecurityBundle\Security;

final class CampaignNotesTools
{
    public function __construct(
        private readonly McpNoteService $notes,
        private readonly Security $security,
    ) {
    }

    #[McpTool(name: 'get_site_overview', description: 'Campaign overview: folders, newest session report summary, and how notes are organized.')]
    public function getSiteOverview(): array
    {
        return $this->notes->getSiteOverview($this->requireUser());
    }

    #[McpTool(name: 'list_session_reports', description: 'List session reports newest-first (paginated). Returns metadata without full body text.')]
    public function listSessionReports(int $page = 1, int $per_page = 20): array
    {
        return $this->notes->listSessionReports($this->requireUser(), max(1, $page), max(1, min($per_page, 50)));
    }

    #[McpTool(name: 'get_session_report', description: 'Full session report markdown by report_number or slug.')]
    public function getSessionReport(?int $report_number = null, ?string $slug = null): array
    {
        if ($report_number === null && $slug === null) {
            throw new \InvalidArgumentException('Provide report_number or slug.');
        }

        $report = $this->notes->getSessionReport($this->requireUser(), $report_number, $slug);
        if ($report === null) {
            return [
                'error' => 'Session report not found.',
                'report_number' => $report_number,
                'slug' => $slug,
            ];
        }

        return $report;
    }

    #[McpTool(name: 'get_note', description: 'Read any lore/reference note by slug or vault_path (e.g. Locations/Deerwater).')]
    public function getNote(?string $slug = null, ?string $vault_path = null): array
    {
        if ($slug === null && $vault_path === null) {
            throw new \InvalidArgumentException('Provide slug or vault_path.');
        }

        $note = $this->notes->getNote($this->requireUser(), $slug, $vault_path);
        if ($note === null) {
            return [
                'error' => 'Note not found.',
                'slug' => $slug,
                'vault_path' => $vault_path,
            ];
        }

        return $note;
    }

    #[McpTool(name: 'search_notes', description: 'Full-text search across note titles and bodies. Optional folder prefix filter (e.g. People).')]
    public function searchNotes(string $query, int $limit = 10, ?string $folder = null): array
    {
        return $this->notes->searchNotes($this->requireUser(), $query, $limit, $folder);
    }

    #[McpTool(name: 'browse_vault', description: 'Browse the vault folder tree (like the site sidebar). Optional folder path to zoom in.')]
    public function browseVault(?string $folder = null): array
    {
        return $this->notes->browseVault($this->requireUser(), $folder);
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('Authentication required.');
        }

        return $user;
    }
}
