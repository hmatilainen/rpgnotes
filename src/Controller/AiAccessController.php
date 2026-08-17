<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Api\UserApiTokenService;
use App\Service\Sidebar\SidebarBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PLAYER')]
final class AiAccessController extends AbstractController
{
    public function __construct(
        private readonly UserApiTokenService $apiTokens,
        private readonly SidebarBuilder $sidebar,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('/ai-access', name: 'ai_access', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $flashToken = $request->getSession()->getFlashBag()->get('api_token')[0] ?? null;

        return $this->render('ai_access/index.html.twig', [
            'sidebar' => $this->sidebar->build($this->isGranted('ROLE_ADMIN')),
            'connector_url' => $this->urlGenerator->generate('_mcp_endpoint', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'flash_token' => \is_string($flashToken) ? $flashToken : null,
        ]);
    }

    #[Route('/ai-access/token', name: 'ai_access_token', methods: ['POST'])]
    public function generateToken(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ai_access_token', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $rawToken = $this->apiTokens->generateForUser($user);
        $this->addFlash('api_token', $rawToken);

        return $this->redirectToRoute('ai_access');
    }
}
