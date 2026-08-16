<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\HiddenPath;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class HiddenPathControllerTest extends WebTestCase
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
        $em->createQuery('DELETE FROM App\Entity\User')->execute();
        $em->createQuery('DELETE FROM App\Entity\HiddenPath')->execute();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/hidden-paths');

        self::assertResponseRedirects('/login');
    }

    public function testAdminCanAddAndRemoveAHiddenPath(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin();
        $client->loginUser($admin);

        $client->request('GET', '/admin/hidden-paths');
        self::assertResponseIsSuccessful();

        $crawler = $client->getCrawler();
        $form = $crawler->filter('form[action$="/admin/hidden-paths/add"]')->form();
        $client->submit($form, ['path' => 'Locations/Deerwater.md']);

        self::assertResponseRedirects('/admin/hidden-paths');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Locations/Deerwater.md');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hiddenPath = $em->getRepository(HiddenPath::class)->findOneBy(['path' => 'Locations/Deerwater.md']);
        self::assertNotNull($hiddenPath);

        $crawler = $client->getCrawler();
        $form = $crawler->filter('form[action$="/' . $hiddenPath->getId() . '/remove"]')->form();
        $client->submit($form);

        self::assertResponseRedirects('/admin/hidden-paths');
        $client->followRedirect();
        self::assertSelectorTextNotContains('body', 'Locations/Deerwater.md');
    }

    private function createAdmin(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setLabel('admin');
        $user->setUsername('admin');
        $user->setRole('ROLE_ADMIN');
        $user->setPasswordHash($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
