<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\NoteRepository;
use App\Service\Share\ShareLinkService;
use App\Service\Sidebar\SidebarBuilder;
use App\Service\Vault\NoteVisibilityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class NoteController extends AbstractController
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly SidebarBuilder $sidebar,
        private readonly ShareLinkService $shareLinks,
        private readonly NoteVisibilityService $visibility,
    ) {
    }

    #[Route('/notes/{slug}/hide', name: 'note_hide', requirements: ['slug' => '.+'], methods: ['POST'], priority: 10)]
    #[IsGranted('ROLE_ADMIN')]
    public function hide(string $slug, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('note_hide', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $note = $this->notes->findOneBySlug($slug);
        if ($note === null) {
            throw $this->createNotFoundException('Note not found.');
        }

        $this->visibility->hide($note);
        $this->addFlash('success', 'Note hidden from players and AI connectors.');

        return $this->redirectToRoute('note_show', ['slug' => $note->getSlug()]);
    }

    #[Route('/notes/{slug}/unhide', name: 'note_unhide', requirements: ['slug' => '.+'], methods: ['POST'], priority: 10)]
    #[IsGranted('ROLE_ADMIN')]
    public function unhide(string $slug, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('note_unhide', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $note = $this->notes->findOneBySlug($slug);
        if ($note === null) {
            throw $this->createNotFoundException('Note not found.');
        }

        if ($this->visibility->unhide($note)) {
            $this->addFlash('success', 'Note is visible again.');
        } else {
            $this->addFlash('warning', 'This note is still hidden by a folder rule. Remove the folder under Admin → Hidden paths.');
        }

        return $this->redirectToRoute('note_show', ['slug' => $note->getSlug()]);
    }

    #[Route('/notes/{slug}/share', name: 'note_share_whatsapp', requirements: ['slug' => '.+'], priority: 10)]
    public function shareWhatsApp(string $slug): RedirectResponse
    {
        $note = $this->notes->findOneBySlug($slug);
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        if ($note === null || ($note->isHidden() && !$isAdmin)) {
            throw $this->createNotFoundException('Note not found.');
        }

        if ($note->getReportNumber() === null) {
            throw $this->createNotFoundException('Note not found.');
        }

        return $this->redirect($this->shareLinks->buildWhatsAppShareUrl($note));
    }

    #[Route('/notes/{slug}', name: 'note_show', requirements: ['slug' => '.+'], priority: 0)]
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
            'isReport' => $reportNumber !== null,
            'hiddenByFolderRule' => $isAdmin && $this->visibility->isHiddenByFolderRule($note),
        ]);
    }
}
