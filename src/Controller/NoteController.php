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

        if ($note === null) {
            throw $this->createNotFoundException('Note not found.');
        }

        return $this->render('note/show.html.twig', [
            'note' => $note,
            'sidebar' => $this->sidebar->build(),
        ]);
    }
}
