<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\OAuthAccessToken;
use App\Entity\OAuthRefreshToken;
use App\Entity\User;
use App\Repository\OAuthAccessTokenRepository;
use App\Repository\OAuthRefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OAuthTokenService
{
    private const ACCESS_TTL_SECONDS = 3600;
    private const REFRESH_TTL_SECONDS = 2592000;

    public function __construct(
        private readonly OAuthAccessTokenRepository $accessTokens,
        private readonly OAuthRefreshTokenRepository $refreshTokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}
     */
    public function issueTokens(User $user, string $clientId, string $scope = OAuthServerConfig::SCOPE): array
    {
        $rawAccess = bin2hex(random_bytes(32));
        $rawRefresh = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+'.self::ACCESS_TTL_SECONDS.' seconds');

        $access = new OAuthAccessToken(
            hash('sha256', $rawAccess),
            $user,
            $clientId,
            $expiresAt,
            $scope,
        );
        $this->em->persist($access);
        $this->em->flush();

        $refresh = new OAuthRefreshToken(
            hash('sha256', $rawRefresh),
            $user,
            $clientId,
            $access,
            new \DateTimeImmutable('+'.self::REFRESH_TTL_SECONDS.' seconds'),
        );
        $this->em->persist($refresh);
        $this->em->flush();

        return [
            'access_token' => $rawAccess,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL_SECONDS,
            'refresh_token' => $rawRefresh,
            'scope' => $scope,
        ];
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}
     */
    public function refresh(string $rawRefreshToken, string $clientId): array
    {
        $refresh = $this->refreshTokens->findOneByTokenHash(hash('sha256', $rawRefreshToken));
        if ($refresh === null || $refresh->getRevokedAt() !== null) {
            throw new OAuthTokenException('invalid_grant', 'Refresh token is invalid.');
        }

        if ($refresh->getExpiresAt() < new \DateTimeImmutable()) {
            throw new OAuthTokenException('invalid_grant', 'Refresh token has expired.');
        }

        if ($refresh->getClientId() !== $clientId) {
            throw new OAuthTokenException('invalid_grant', 'Client mismatch.');
        }

        $refresh->revoke();
        $this->em->flush();

        return $this->issueTokens($refresh->getUser(), $clientId, $refresh->getAccessToken()->getScope());
    }

    public function findUserByAccessToken(string $rawToken): ?User
    {
        $token = $this->accessTokens->findOneByTokenHash(hash('sha256', $rawToken));
        if ($token === null) {
            return null;
        }

        if ($token->getExpiresAt() < new \DateTimeImmutable()) {
            return null;
        }

        return $token->getUser();
    }
}
