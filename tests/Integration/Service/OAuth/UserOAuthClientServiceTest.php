<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\OAuth;

use App\Entity\User;
use App\Service\OAuth\UserOAuthClientService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserOAuthClientServiceTest extends KernelTestCase
{
    private UserOAuthClientService $service;
    private User $user;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->service = $container->get(UserOAuthClientService::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->em->createQuery('DELETE FROM App\Entity\UserOAuthClient')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();

        $this->user = new User();
        $this->user->setLabel('OAuth Player');
        $this->user->setUsername('oauth-player');
        $this->user->setRole('ROLE_PLAYER');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'password123'));
        $this->em->persist($this->user);
        $this->em->flush();
    }

    public function testEnsureForUserCreatesClient(): void
    {
        $client = $this->service->ensureForUser($this->user);

        self::assertSame($this->user, $client->getUser());
        self::assertStringStartsWith('rpg_', $client->getClientId());
        self::assertSame(['https://claude.ai/api/mcp/auth_callback'], $client->getRedirectUris());
    }

    public function testEnsureForUserReturnsExistingWithoutRegenerating(): void
    {
        $first = $this->service->ensureForUser($this->user);
        $second = $this->service->ensureForUser($this->user);

        self::assertSame($first->getId(), $second->getId());
        self::assertSame($first->getClientId(), $second->getClientId());
    }

    public function testRegenerateForUserReturnsNewCredentials(): void
    {
        $this->service->ensureForUser($this->user);
        $result = $this->service->regenerateForUser($this->user);

        self::assertArrayHasKey('clientId', $result);
        self::assertArrayHasKey('clientSecret', $result);
        self::assertStringStartsWith('rpg_', $result['clientId']);
        self::assertSame(64, strlen($result['clientSecret']));
    }

    public function testValidateClientWithCorrectSecret(): void
    {
        $result = $this->service->regenerateForUser($this->user);

        $validated = $this->service->validateClient(
            $result['clientId'],
            'https://claude.ai/api/mcp/auth_callback',
            $result['clientSecret'],
        );

        self::assertNotNull($validated);
        self::assertSame($result['clientId'], $validated->getClientId());
    }

    public function testValidateClientWithoutSecret(): void
    {
        $result = $this->service->regenerateForUser($this->user);

        $validated = $this->service->validateClient(
            $result['clientId'],
            'https://claude.ai/api/mcp/auth_callback',
            null,
        );

        self::assertNotNull($validated);
    }

    public function testValidateClientRejectsWrongSecret(): void
    {
        $result = $this->service->regenerateForUser($this->user);

        $validated = $this->service->validateClient(
            $result['clientId'],
            'https://claude.ai/api/mcp/auth_callback',
            'wrong-secret',
        );

        self::assertNull($validated);
    }

    public function testValidateClientRejectsWrongRedirectUri(): void
    {
        $result = $this->service->regenerateForUser($this->user);

        $validated = $this->service->validateClient(
            $result['clientId'],
            'https://evil.example/callback',
            $result['clientSecret'],
        );

        self::assertNull($validated);
    }
}
