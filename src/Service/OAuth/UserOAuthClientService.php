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

        $credentials = $this->regenerateForUser($user);
        $client = $this->clients->findOneByClientId($credentials['clientId']);
        if ($client === null) {
            throw new \RuntimeException('OAuth client was not persisted.');
        }

        return $client;
    }

    /**
     * @return array{clientId: string, clientSecret: string}
     */
    public function regenerateForUser(User $user): array
    {
        $clientId = 'rpg_'.bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $hash = $this->hashSecret($secret);

        $client = $this->clients->findOneByUser($user);
        if ($client === null) {
            $client = new UserOAuthClient($user, $clientId, $hash, [self::CLAUDE_CALLBACK]);
            $this->em->persist($client);
        } else {
            $client->setClientId($clientId);
            $client->setClientSecretHash($hash);
            $client->setRedirectUris([self::CLAUDE_CALLBACK]);
        }

        $this->em->flush();

        return ['clientId' => $clientId, 'clientSecret' => $secret];
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
