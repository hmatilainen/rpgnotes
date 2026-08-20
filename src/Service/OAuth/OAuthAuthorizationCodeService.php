<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\OAuthAuthorizationCode;
use App\Entity\User;
use App\Repository\OAuthAuthorizationCodeRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OAuthAuthorizationCodeService
{
    public function __construct(
        private readonly OAuthAuthorizationCodeRepository $codes,
        private readonly EntityManagerInterface $em,
        private readonly PkceValidator $pkce,
        private readonly OAuthServerConfig $config,
    ) {
    }

    public function create(
        User $user,
        string $clientId,
        string $redirectUri,
        string $codeChallenge,
        ?string $resource,
        string $scope = OAuthServerConfig::SCOPE,
    ): string {
        $raw = bin2hex(random_bytes(32));
        $code = new OAuthAuthorizationCode(
            hash('sha256', $raw),
            $clientId,
            $user,
            $redirectUri,
            $codeChallenge,
            new \DateTimeImmutable('+5 minutes'),
            $resource,
            $scope,
        );
        $this->em->persist($code);
        $this->em->flush();

        return $raw;
    }

    public function redeem(
        string $rawCode,
        string $clientId,
        string $redirectUri,
        string $codeVerifier,
        ?string $resource,
    ): User {
        $code = $this->codes->findOneByCodeHash(hash('sha256', $rawCode));
        if ($code === null) {
            throw new OAuthTokenException('invalid_grant', 'Authorization code is invalid.');
        }

        if ($code->getUsedAt() !== null) {
            throw new OAuthTokenException('invalid_grant', 'Authorization code has already been used.');
        }

        if ($code->getExpiresAt() < new \DateTimeImmutable()) {
            throw new OAuthTokenException('invalid_grant', 'Authorization code has expired.');
        }

        if ($code->getClientId() !== $clientId) {
            throw new OAuthTokenException('invalid_grant', 'Client mismatch.');
        }

        if ($code->getRedirectUri() !== $redirectUri) {
            throw new OAuthTokenException('invalid_grant', 'Redirect URI mismatch.');
        }

        if (!$this->pkce->verify($codeVerifier, $code->getCodeChallenge())) {
            throw new OAuthTokenException('invalid_grant', 'PKCE verification failed.');
        }

        if ($resource !== null && $resource !== '' && $resource !== $this->config->getResource()) {
            throw new OAuthTokenException('invalid_grant', 'Resource mismatch.');
        }

        $storedResource = $code->getResource();
        if ($storedResource !== null && $storedResource !== '' && $storedResource !== $this->config->getResource()) {
            throw new OAuthTokenException('invalid_grant', 'Resource mismatch.');
        }

        $code->markUsed();
        $this->em->flush();

        return $code->getUser();
    }
}
