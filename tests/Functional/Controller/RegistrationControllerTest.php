<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUpUsers();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $this->cleanUpUsers();
        parent::tearDown();
    }

    private function cleanUpUsers(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testValidTokenRegistersAndAllowsLogin(): void
    {
        $client = static::createClient();
        $this->makePendingPlayer('Mikko', 'valid-token-123', new \DateTimeImmutable('+1 day'));

        $client->request('GET', '/register/valid-token-123');
        self::assertResponseIsSuccessful();

        $client->submitForm('Create account', [
            'username' => 'mikko99',
            'password' => 'a-strong-password',
        ]);
        self::assertResponseRedirects('/login');

        $client->request('GET', '/login');
        $client->submitForm('Log in', [
            '_username' => 'mikko99',
            '_password' => 'a-strong-password',
        ]);
        self::assertResponseRedirects('/');
    }

    public function testTokenIsConsumedAfterUse(): void
    {
        $client = static::createClient();
        $this->makePendingPlayer('Mikko', 'one-time-token', new \DateTimeImmutable('+1 day'));

        $client->request('GET', '/register/one-time-token');
        $client->submitForm('Create account', [
            'username' => 'mikko99',
            'password' => 'a-strong-password',
        ]);

        $client->request('GET', '/register/one-time-token');
        self::assertResponseStatusCodeSame(404);
    }

    public function testExpiredTokenShowsInvalidPage(): void
    {
        $client = static::createClient();
        $this->makePendingPlayer('Mikko', 'expired-token', new \DateTimeImmutable('-1 day'));

        $client->request('GET', '/register/expired-token');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'no longer valid');
    }

    public function testNonexistentTokenShowsInvalidPage(): void
    {
        $client = static::createClient();

        $client->request('GET', '/register/never-issued');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'no longer valid');
    }

    public function testDuplicateUsernameShowsFormError(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $existing = new User();
        $existing->setLabel('Existing');
        $existing->setUsername('taken');
        $existing->setRole('ROLE_PLAYER');
        $existing->setPasswordHash($hasher->hashPassword($existing, 'whatever'));
        $em->persist($existing);

        $this->makePendingPlayer('Mikko', 'dup-token', new \DateTimeImmutable('+1 day'));

        $client->request('GET', '/register/dup-token');
        $client->submitForm('Create account', [
            'username' => 'taken',
            'password' => 'a-strong-password',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.form-error', 'already taken');
    }

    private function makePendingPlayer(string $label, string $token, \DateTimeImmutable $expiresAt): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setLabel($label);
        $user->setRole('ROLE_PLAYER');
        $user->setInviteToken($token);
        $user->setInviteTokenExpiresAt($expiresAt);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
