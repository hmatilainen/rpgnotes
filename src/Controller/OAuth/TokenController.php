<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Service\OAuth\OAuthAuthorizationCodeService;
use App\Service\OAuth\OAuthClientRegistry;
use App\Service\OAuth\OAuthServerConfig;
use App\Service\OAuth\OAuthTokenException;
use App\Service\OAuth\OAuthTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/oauth')]
final class TokenController extends AbstractController
{
    public function __construct(
        private readonly OAuthClientRegistry $clients,
        private readonly OAuthAuthorizationCodeService $authorizationCodes,
        private readonly OAuthTokenService $tokens,
        private readonly OAuthServerConfig $config,
    ) {
    }

    #[Route('/token', name: 'oauth_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        $grantType = (string) $request->request->get('grant_type', '');

        try {
            if ($grantType === 'authorization_code') {
                return new JsonResponse($this->handleAuthorizationCode($request));
            }

            if ($grantType === 'refresh_token') {
                return new JsonResponse($this->handleRefreshToken($request));
            }
        } catch (OAuthTokenException $e) {
            return new JsonResponse(['error' => $e->error, 'error_description' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['error' => 'unsupported_grant_type'], Response::HTTP_BAD_REQUEST);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleAuthorizationCode(Request $request): array
    {
        $clientId = (string) $request->request->get('client_id', '');
        $redirectUri = (string) $request->request->get('redirect_uri', '');
        $code = (string) $request->request->get('code', '');
        $codeVerifier = (string) $request->request->get('code_verifier', '');
        $clientSecret = $request->request->get('client_secret');
        $resource = $request->request->get('resource');

        if ($clientId === '' || $redirectUri === '' || $code === '' || $codeVerifier === '') {
            throw new OAuthTokenException('invalid_request', 'Missing required parameters.');
        }

        $secret = is_string($clientSecret) && $clientSecret !== '' ? $clientSecret : null;
        if (!$this->clients->validateTokenRequest($clientId, $redirectUri, $secret)) {
            throw new OAuthTokenException('invalid_client', 'Client authentication failed.');
        }

        $resourceValue = is_string($resource) ? $resource : null;
        if ($resourceValue !== null && $resourceValue !== '' && $resourceValue !== $this->config->getResource()) {
            throw new OAuthTokenException('invalid_grant', 'Resource mismatch.');
        }

        $user = $this->authorizationCodes->redeem($code, $clientId, $redirectUri, $codeVerifier, $resourceValue);

        return $this->tokens->issueTokens($user, $clientId);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleRefreshToken(Request $request): array
    {
        $clientId = (string) $request->request->get('client_id', '');
        $refreshToken = (string) $request->request->get('refresh_token', '');

        if ($clientId === '' || $refreshToken === '') {
            throw new OAuthTokenException('invalid_request', 'Missing required parameters.');
        }

        return $this->tokens->refresh($refreshToken, $clientId);
    }
}
