<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Entity\OAuthDynamicClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/oauth')]
final class RegisterController extends AbstractController
{
    private const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/register', name: 'oauth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid_client_metadata'], Response::HTTP_BAD_REQUEST);
        }

        $redirectUris = $payload['redirect_uris'] ?? [];
        if (!is_array($redirectUris) || $redirectUris === []) {
            return new JsonResponse(['error' => 'invalid_redirect_uri'], Response::HTTP_BAD_REQUEST);
        }

        $normalized = [];
        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || $uri === '') {
                return new JsonResponse(['error' => 'invalid_redirect_uri'], Response::HTTP_BAD_REQUEST);
            }
            $normalized[] = $uri;
        }

        if (!in_array(self::CLAUDE_CALLBACK, $normalized, true)) {
            return new JsonResponse(['error' => 'invalid_redirect_uri'], Response::HTTP_BAD_REQUEST);
        }

        $clientId = 'rpg_dcr_'.bin2hex(random_bytes(12));
        $client = new OAuthDynamicClient($clientId, $normalized);
        $this->em->persist($client);
        $this->em->flush();

        return new JsonResponse([
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'redirect_uris' => $normalized,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], Response::HTTP_CREATED);
    }
}
