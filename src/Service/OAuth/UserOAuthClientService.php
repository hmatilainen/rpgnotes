<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\User;
use App\Entity\UserOAuthClient;
use App\Repository\UserOAuthClientRepository;
use Doctrine\ORM\EntityManagerInterface;

final class UserOAuthClientService
{
    private const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    public function __construct(
        private readonly UserOAuthClientRepository $clients,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function hashSecret(string $rawSecret): string
    {
        return hash('sha256', $rawSecret);
    }

    public function ensureForUser(User $user): UserOAuthClient
    {
        $client = $this->clients->findOneByUser($user);
        if ($client !== null) {
            return $client;
        }

        $clientId = 'rpg_'.bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $client = new UserOAuthClient(
            $user,
            $clientId,
            $this->hashSecret($secret),
            [self::CLAUDE_CALLBACK],
        );
        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    /**
     * Issue a new client secret. The client ID stays the same so Claude settings
     * copied earlier remain valid.
     *
     * @return array{clientId: string, clientSecret: string}
     */
    public function regenerateSecretForUser(User $user): array
    {
        $client = $this->ensureForUser($user);
        $secret = bin2hex(random_bytes(32));
        $client->setClientSecretHash($this->hashSecret($secret));
        $this->em->flush();

        return ['clientId' => $client->getClientId(), 'clientSecret' => $secret];
    }

    /**
     * @return array{clientId: string, clientSecret: string}
     */
    public function regenerateForUser(User $user): array
    {
        return $this->regenerateSecretForUser($user);
    }

    public function validateClient(string $clientId, string $redirectUri, ?string $clientSecret): ?UserOAuthClient
    {
        $client = $this->clients->findOneByClientId($clientId);
        if ($client === null) {
            return null;
        }

        if (!in_array($redirectUri, $client->getRedirectUris(), true)) {
            return null;
        }

        if ($clientSecret !== null && !hash_equals($client->getClientSecretHash(), $this->hashSecret($clientSecret))) {
            return null;
        }

        return $client;
    }
}
