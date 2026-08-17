<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Note;
use App\Entity\ShareToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SessionReportControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\Entity\SessionNoteDraft')->execute();
        $em->createQuery('DELETE FROM App\Entity\ShareToken')->execute();
        $em->createQuery('DELETE FROM App\Entity\Note')->execute();
        $em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/reports/new');
        self::assertResponseRedirects('/login');
    }

    public function testPlayerCanSaveDraft(): void
    {
        $client = static::createClient();
        $this->createPlayer('player1', 'secret-password');

        $client->loginUser($this->findUser('player1'));
        $crawler = $client->request('GET', '/reports/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save draft')->form([
            'title' => 'Campfire tales',
            'session_date' => '16.8.1367',
            'body' => 'We rested by the fire.',
            'action' => 'save',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/reports/new');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Draft saved');

        $draft = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(\App\Entity\SessionNoteDraft::class)
            ->findOneBy([]);
        self::assertNotNull($draft);
        self::assertSame('Campfire tales', $draft->getTitle());
    }

    private function createPlayer(string $username, string $password): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setLabel('Player');
        $user->setUsername($username);
        $user->setRole('ROLE_PLAYER');
        $user->setPasswordHash($hasher->hashPassword($user, $password));
        $em->persist($user);
        $em->flush();
    }

    private function findUser(string $username): User
    {
        return static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(User::class)
            ->findOneBy(['username' => $username]) ?? throw new \RuntimeException('User not found');
    }
}
