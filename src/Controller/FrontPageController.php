<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\NoteRepository;
use App\Service\Sidebar\SidebarBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FrontPageController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly SidebarBuilder $sidebar,
    ) {
    }

    #[Route('/', name: 'front_page')]
    public function __invoke(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $reports = $this->notes->findReportsPaginated($page, self::PER_PAGE);
        $total = $this->notes->countReports();

        return $this->render('front_page/index.html.twig', [
            'reports' => $reports,
            'page' => $page,
            'totalPages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'sidebar' => $this->sidebar->build(),
        ]);
    }
}
