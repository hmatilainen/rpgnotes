<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SessionNoteDraft;
use App\Entity\User;
use App\Repository\SessionNoteDraftRepository;
use App\Service\SessionReport\InGameDateParser;
use App\Service\SessionReport\SessionNotePublisher;
use App\Service\Share\ShareLinkService;
use App\Service\Sidebar\SidebarBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLAYER')]
final class SessionReportController extends AbstractController
{
    public function __construct(
        private readonly SessionNoteDraftRepository $drafts,
        private readonly EntityManagerInterface $em,
        private readonly InGameDateParser $dateParser,
        private readonly SessionNotePublisher $publisher,
        private readonly ShareLinkService $shareLinks,
        private readonly SidebarBuilder $sidebar,
    ) {
    }

    #[Route('/reports/new', name: 'session_report_new', methods: ['GET'])]
    public function newForm(): Response
    {
        $user = $this->requirePlayer();
        $draft = $this->drafts->findOneByAuthor($user);

        return $this->render('session_report/new.html.twig', [
            'draft' => $draft,
            'sidebar' => $this->sidebar->build($this->isGranted('ROLE_ADMIN')),
        ]);
    }

    #[Route('/reports/new', name: 'session_report_save', methods: ['POST'])]
    public function handleForm(Request $request): Response
    {
        $tokenName = 'session_report';
        if (!$this->isCsrfTokenValid($tokenName, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->requirePlayer();
        $draft = $this->loadOrCreateDraft($user);
        $errors = $this->applyFormData($request, $draft);
        $isPublish = $request->request->get('action') === 'publish';

        if ($errors !== []) {
            return $this->render('session_report/new.html.twig', [
                'draft' => $draft,
                'errors' => $errors,
                'sidebar' => $this->sidebar->build($this->isGranted('ROLE_ADMIN')),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $draft->touch();
        $this->em->persist($draft);
        $this->em->flush();

        if (!$isPublish) {
            $this->addFlash('success', 'Draft saved.');

            return $this->redirectToRoute('session_report_new');
        }

        try {
            $shareToken = $this->publisher->publish($draft);
        } catch (\RuntimeException $e) {
            return $this->render('session_report/new.html.twig', [
                'draft' => $draft,
                'errors' => ['publish' => 'Could not publish to GitHub. Try again later or ask the GM to sync from GitHub.'],
                'sidebar' => $this->sidebar->build($this->isGranted('ROLE_ADMIN')),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $note = $shareToken->getNote();
        $shareUrl = $this->shareLinks->buildPublicShareUrl($shareToken);
        $whatsappUrl = $this->shareLinks->buildWhatsAppShareUrl($note);

        $this->addFlash('published_report', [
            'note_slug' => $note->getSlug(),
            'share_url' => $shareUrl,
            'whatsapp_url' => $whatsappUrl,
            'report_number' => $note->getReportNumber(),
            'title' => $note->getTitle(),
        ]);

        return $this->redirectToRoute('session_report_published');
    }

    #[Route('/reports/published', name: 'session_report_published', methods: ['GET'])]
    public function published(Request $request): Response
    {
        $flashes = $request->getSession()->getFlashBag()->get('published_report');
        $flash = $flashes[0] ?? null;

        if (!\is_array($flash)) {
            return $this->redirectToRoute('session_report_new');
        }

        return $this->render('session_report/published.html.twig', [
            'published' => $flash,
            'sidebar' => $this->sidebar->build($this->isGranted('ROLE_ADMIN')),
        ]);
    }

    private function requirePlayer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function loadOrCreateDraft(User $user): SessionNoteDraft
    {
        $draft = $this->drafts->findOneByAuthor($user);

        return $draft ?? new SessionNoteDraft($user);
    }

    /**
     * @return array<string, string>
     */
    private function applyFormData(Request $request, SessionNoteDraft $draft): array
    {
        $errors = [];

        $title = trim((string) $request->request->get('title'));
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = 'Title is too long.';
        } else {
            $draft->setTitle($title);
        }

        $sessionDateInput = trim((string) $request->request->get('session_date'));
        $sessionDate = $this->dateParser->parse($sessionDateInput);
        if ($sessionDate === null) {
            $errors['session_date'] = 'Enter the in-game date as day.month.year (for example 16.8.1367).';
        } else {
            $draft->setSessionDate($sessionDate);
        }

        $body = (string) $request->request->get('body');
        $trimmedBody = trim($body);
        if ($trimmedBody === '') {
            $errors['body'] = 'Session notes cannot be empty.';
        } else {
            $draft->setBody($body);
        }

        return $errors;
    }
}
