<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Vault\GitSyncService;
use App\Service\Vault\NoteIndexer;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class WebhookController extends AbstractController
{
    public function __construct(
        private readonly GitSyncService $gitSync,
        private readonly NoteIndexer $indexer,
        private readonly LoggerInterface $logger,
        private readonly string $vaultPath,
        private readonly string $webhookSecret,
    ) {
    }

    #[Route('/webhook/sync', name: 'webhook_sync', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $authHeader = (string) $request->headers->get('Authorization', '');

        if (!hash_equals('Bearer ' . $this->webhookSecret, $authHeader)) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        try {
            $this->gitSync->sync();
            $result = $this->indexer->index($this->vaultPath);
        } catch (\Throwable $e) {
            $this->logger->error('Notes sync failed', ['exception' => $e]);

            return new JsonResponse(['error' => 'sync failed'], 500);
        }

        return new JsonResponse(['updated' => $result->updated, 'deleted' => $result->deleted]);
    }
}
