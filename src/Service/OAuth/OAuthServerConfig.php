<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class OAuthServerConfig
{
    public const SCOPE = 'mcp:read';

    public function __construct(
        private readonly string $defaultUri,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getIssuer(): string
    {
        return rtrim($this->defaultUri, '/');
    }

    public function getResource(): string
    {
        return $this->getIssuer().$this->urlGenerator->generate('_mcp_endpoint');
    }

    public function getProtectedResourceMetadataUrl(): string
    {
        return $this->getIssuer().$this->urlGenerator->generate('oauth_protected_resource_metadata');
    }

    public function getAuthorizationServerMetadataUrl(): string
    {
        return $this->getIssuer().$this->urlGenerator->generate('oauth_authorization_server_metadata');
    }

    public function getAuthorizationEndpoint(): string
    {
        return $this->getIssuer().'/authorize';
    }

    public function getTokenEndpoint(): string
    {
        return $this->getIssuer().'/oauth/token';
    }

    public function getRegistrationEndpoint(): string
    {
        return $this->getIssuer().'/oauth/register';
    }
}
