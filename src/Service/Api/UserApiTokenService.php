<?php

declare(strict_types=1);

namespace App\Service\Api;

use App\Entity\User;
use App\Entity\UserApiToken;
use App\Repository\UserApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

final class UserApiTokenService
{
    public function __construct(
        private readonly UserApiTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Creates or replaces the user's API token and returns the raw token (show once).
     */
    public function generateForUser(User $user): string
    {
        $raw = bin2hex(random_bytes(32));
        $hash = $this->hashToken($raw);

        $token = $this->tokens->findOneByUser($user);
        if ($token === null) {
            $token = new UserApiToken($user, $hash);
            $this->em->persist($token);
        } else {
            $token->setTokenHash($hash);
        }

        $this->em->flush();

        return $raw;
    }

    public function findUserByRawToken(string $rawToken): ?User
    {
        $apiToken = $this->tokens->findOneByTokenHash($this->hashToken($rawToken));

        return $apiToken?->getUser();
    }
}
