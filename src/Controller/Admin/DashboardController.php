<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Sidebar\SidebarBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_dashboard')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly SidebarBuilder $sidebar,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'sidebar' => $this->sidebar->build(),
        ]);
    }
}
