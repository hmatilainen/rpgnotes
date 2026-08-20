<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Service\OAuth\OAuthServerConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class MetadataController extends AbstractController
{
    #[Route(
        '/.well-known/oauth-protected-resource',
        name: 'oauth_protected_resource_metadata',
        methods: ['GET'],
    )]
    public function protectedResource(OAuthServerConfig $config): JsonResponse
    {
        return new JsonResponse([
            'resource' => $config->getResource(),
            'authorization_servers' => [$config->getIssuer()],
            'scopes_supported' => [OAuthServerConfig::SCOPE],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    #[Route(
        '/.well-known/oauth-authorization-server',
        name: 'oauth_authorization_server_metadata',
        methods: ['GET'],
    )]
    public function authorizationServer(OAuthServerConfig $config): JsonResponse
    {
        return new JsonResponse([
            'issuer' => $config->getIssuer(),
            'authorization_endpoint' => $config->getAuthorizationEndpoint(),
            'token_endpoint' => $config->getTokenEndpoint(),
            'registration_endpoint' => $config->getRegistrationEndpoint(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'scopes_supported' => [OAuthServerConfig::SCOPE],
        ]);
    }
}
