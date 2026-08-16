<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SecurityControllerTest extends WebTestCase
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

    public function testLoginSucceedsWithCorrectCredentials(): void
    {
        $client = static::createClient();
        $this->createUser($client, 'alice', 'correct-password', 'ROLE_ADMIN');

        $client->request('GET', '/login');
        $client->submitForm('Log in', [
            '_username' => 'alice',
            '_password' => 'correct-password',
        ]);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Logged in as alice');
    }

    public function testLoginFailsWithWrongPassword(): void
    {
        $client = static::createClient();
        $this->createUser($client, 'bob', 'correct-password', 'ROLE_PLAYER');

        $client->request('GET', '/login');
        $client->submitForm('Log in', [
            '_username' => 'bob',
            '_password' => 'wrong-password',
        ]);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Invalid username or password.');
    }

    public function testLogoutEndsSession(): void
    {
        $client = static::createClient();
        $this->createUser($client, 'carol', 'correct-password', 'ROLE_PLAYER');

        $client->request('GET', '/login');
        $client->submitForm('Log in', [
            '_username' => 'carol',
            '_password' => 'correct-password',
        ]);
        $client->followRedirect();

        $client->submitForm('Log out');
        $client->followRedirect();

        self::assertSelectorExists('a[href="/login"]');
    }

    private function createUser(KernelBrowser $client, string $username, string $password, string $role): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setLabel($username);
        $user->setUsername($username);
        $user->setRole($role);
        $user->setPasswordHash($hasher->hashPassword($user, $password));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
