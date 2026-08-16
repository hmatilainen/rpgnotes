<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Sidebar\SidebarBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/players')]
final class PlayerController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly SidebarBuilder $sidebar,
    ) {
    }

    #[Route('', name: 'admin_players', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/players.html.twig', [
            'players' => $this->users->findAllPlayers(),
            'sidebar' => $this->sidebar->build(),
        ]);
    }

    #[Route('/add', name: 'admin_players_add', methods: ['POST'])]
    public function add(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_players_add', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $label = trim((string) $request->request->get('label'));

        if ($label !== '') {
            $player = new User();
            $player->setLabel($label);
            $player->setRole('ROLE_PLAYER');
            $this->em->persist($player);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_players');
    }

    #[Route('/{id}/invite', name: 'admin_players_invite', methods: ['POST'])]
    public function generateInvite(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_players_invite', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->users->find($id);

        if ($player === null || $player->getRole() !== 'ROLE_PLAYER') {
            throw $this->createNotFoundException();
        }

        $player->setInviteToken(bin2hex(random_bytes(32)));
        $player->setInviteTokenExpiresAt(new \DateTimeImmutable('+2 weeks'));
        $this->em->flush();

        return $this->redirectToRoute('admin_players');
    }
}
