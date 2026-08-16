<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\HiddenPath;
use App\Repository\HiddenPathRepository;
use App\Service\Sidebar\SidebarBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/hidden-paths')]
final class HiddenPathController extends AbstractController
{
    public function __construct(
        private readonly HiddenPathRepository $hiddenPaths,
        private readonly EntityManagerInterface $em,
        private readonly SidebarBuilder $sidebar,
    ) {
    }

    #[Route('', name: 'admin_hidden_paths', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/hidden_paths.html.twig', [
            'hiddenPaths' => $this->hiddenPaths->findBy([], ['path' => 'ASC']),
            'sidebar' => $this->sidebar->build(),
        ]);
    }

    #[Route('/add', name: 'admin_hidden_paths_add', methods: ['POST'])]
    public function add(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_hidden_paths_add', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $path = trim((string) $request->request->get('path'), " \t\n\r\0\x0B/");

        if ($path !== '' && $this->hiddenPaths->findOneBy(['path' => $path]) === null) {
            $hiddenPath = new HiddenPath();
            $hiddenPath->setPath($path);
            $this->em->persist($hiddenPath);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_hidden_paths');
    }

    #[Route('/{id}/remove', name: 'admin_hidden_paths_remove', methods: ['POST'])]
    public function remove(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_hidden_paths_remove', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $hiddenPath = $this->hiddenPaths->find($id);

        if ($hiddenPath !== null) {
            $this->em->remove($hiddenPath);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_hidden_paths');
    }
}
