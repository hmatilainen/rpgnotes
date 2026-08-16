<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PlayerControllerTest extends WebTestCase
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

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/players');

        self::assertResponseRedirects('/login');
    }

    public function testNonAdminGetsForbidden(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player1', 'ROLE_PLAYER');
        $client->loginUser($player);

        $client->request('GET', '/admin/players');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanViewAndAddPlayer(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin1', 'ROLE_ADMIN');
        $client->loginUser($admin);

        $client->request('GET', '/admin/players');
        self::assertResponseIsSuccessful();

        $crawler = $client->getCrawler();
        $form = $crawler->filter('form[action$="/admin/players/add"]')->form();
        $client->submit($form, ['label' => 'Mikko']);

        self::assertResponseRedirects('/admin/players');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Mikko');
    }

    public function testAdminCanGenerateAndRegenerateInvite(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin2', 'ROLE_ADMIN');
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $player = new User();
        $player->setLabel('Mikko');
        $player->setRole('ROLE_PLAYER');
        $em->persist($player);
        $em->flush();

        $client->request('GET', '/admin/players');
        $crawler = $client->getCrawler();
        $form = $crawler->filter('form[action$="/' . $player->getId() . '/invite"]')->form();
        $client->submit($form);

        self::assertResponseRedirects('/admin/players');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $player = $em->find(User::class, $player->getId());
        $firstToken = $player->getInviteToken();
        self::assertNotNull($firstToken);
        self::assertTrue($player->isInviteValid());

        $client->request('GET', '/admin/players');
        $crawler = $client->getCrawler();
        $form = $crawler->filter('form[action$="/' . $player->getId() . '/invite"]')->form();
        $client->submit($form);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $player = $em->find(User::class, $player->getId());
        self::assertNotSame($firstToken, $player->getInviteToken());
    }

    private function createUser(string $username, string $role): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setLabel($username);
        $user->setUsername($username);
        $user->setRole($role);
        $user->setPasswordHash($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
