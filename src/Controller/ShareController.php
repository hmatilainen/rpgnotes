<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ShareTokenRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShareController extends AbstractController
{
    public function __construct(
        private readonly ShareTokenRepository $shareTokens,
    ) {
    }

    #[Route('/share/{token}', name: 'share_show', requirements: ['token' => '[a-f0-9]+'])]
    public function show(string $token): Response
    {
        $shareToken = $this->shareTokens->findOneByToken($token);
        if ($shareToken === null) {
            throw $this->createNotFoundException('Share link not found.');
        }

        $note = $shareToken->getNote();
        if ($note->isHidden()) {
            throw $this->createNotFoundException('Share link not found.');
        }

        if ($note->getReportNumber() === null) {
            throw $this->createNotFoundException('Share link not found.');
        }

        return $this->render('share/show.html.twig', [
            'note' => $note,
        ]);
    }
}
