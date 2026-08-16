<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\NoteRepository;
use App\Service\Sidebar\SidebarBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NoteController extends AbstractController
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly SidebarBuilder $sidebar,
    ) {
    }

    #[Route('/notes/{slug}', name: 'note_show', requirements: ['slug' => '.+'])]
    public function __invoke(string $slug): Response
    {
        $note = $this->notes->findOneBySlug($slug);
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        if ($note === null || ($note->isHidden() && !$isAdmin)) {
            throw $this->createNotFoundException('Note not found.');
        }

        $reportNumber = $note->getReportNumber();

        return $this->render('note/show.html.twig', [
            'note' => $note,
            'previousReport' => $reportNumber !== null ? $this->notes->findPreviousReport($reportNumber, $isAdmin) : null,
            'nextReport' => $reportNumber !== null ? $this->notes->findNextReport($reportNumber, $isAdmin) : null,
            'sidebar' => $this->sidebar->build($isAdmin),
        ]);
    }
}
