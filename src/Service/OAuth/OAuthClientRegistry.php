<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Repository\OAuthDynamicClientRepository;
use App\Repository\UserOAuthClientRepository;

final class OAuthClientRegistry
{
    public function __construct(
        private readonly UserOAuthClientRepository $userClients,
        private readonly OAuthDynamicClientRepository $dynamicClients,
        private readonly UserOAuthClientService $userClientService,
    ) {
    }

    public function allowsAuthorizeRequest(string $clientId, string $redirectUri): bool
    {
        $userClient = $this->userClients->findOneByClientId($clientId);
        if ($userClient !== null) {
            return in_array($redirectUri, $userClient->getRedirectUris(), true);
        }

        $dynamic = $this->dynamicClients->findOneByClientId($clientId);
        if ($dynamic !== null) {
            return in_array($redirectUri, $dynamic->getRedirectUris(), true);
        }

        return false;
    }

    public function validateTokenRequest(
        string $clientId,
        string $redirectUri,
        ?string $clientSecret,
    ): bool {
        if ($this->userClientService->validateClient($clientId, $redirectUri, $clientSecret) !== null) {
            return true;
        }

        if ($clientSecret !== null && $clientSecret !== '') {
            return false;
        }

        $dynamic = $this->dynamicClients->findOneByClientId($clientId);

        return $dynamic !== null && in_array($redirectUri, $dynamic->getRedirectUris(), true);
    }
}
