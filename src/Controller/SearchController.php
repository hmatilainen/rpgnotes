<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Note;
use App\Repository\NoteRepository;
use App\Service\Share\WhatsAppShareMessageBuilder;
use App\Service\Sidebar\SidebarBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractController
{
    private const HTML_LIMIT = 50;
    private const JSON_LIMIT = 8;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly SidebarBuilder $sidebar,
        private readonly WhatsAppShareMessageBuilder $excerpts,
    ) {
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function html(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $results = $query === ''
            ? []
            : $this->notes->searchByText($query, $isAdmin, self::HTML_LIMIT, null);

        $hits = array_map(fn (Note $note) => $this->presentHit($note, withExcerpt: true), $results);

        return $this->render('search/index.html.twig', [
            'query' => $query,
            'hits' => $hits,
            'sidebar' => $this->sidebar->build($isAdmin),
        ]);
    }

    #[Route('/search.json', name: 'search_json', methods: ['GET'])]
    public function typeahead(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $results = $query === ''
            ? []
            : $this->notes->searchByText($query, $isAdmin, self::JSON_LIMIT, null);

        return $this->json([
            'query' => $query,
            'results' => array_map(fn (Note $note) => $this->presentHit($note, withExcerpt: false), $results),
        ]);
    }

    /**
     * @return array{title: string, slug: string, url: string, path: string, excerpt?: string}
     */
    private function presentHit(Note $note, bool $withExcerpt): array
    {
        $hit = [
            'title' => $note->getTitle(),
            'slug' => $note->getSlug(),
            'url' => '/notes/'.$note->getSlug(),
            'path' => $note->getVaultPath(),
        ];

        if ($withExcerpt) {
            $plain = trim(strip_tags($note->getHtml()));
            $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $hit['excerpt'] = $this->excerpts->excerptFromText($plain);
        }

        return $hit;
    }
}
