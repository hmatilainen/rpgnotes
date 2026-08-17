<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\HiddenPath;
use App\Entity\Note;
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
        $em->createQuery('DELETE FROM App\Entity\Note')->execute();
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

    public function testAddingFolderHiddenPathHidesMatchingNotesImmediately(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $note = new Note();
        $note->setVaultPath('A - GM/Secret Plot.md');
        $note->setSlug('a-gm/secret-plot');
        $note->setTitle('Secret Plot');
        $note->setTopLevelFolder('A - GM');
        $note->setHtml('<p>Top secret.</p>');
        $em->persist($note);
        $em->flush();

        $client->request('GET', '/notes/a-gm/secret-plot');
        self::assertResponseIsSuccessful();

        $admin = $this->createAdmin();
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/hidden-paths');
        $form = $crawler->filter('form[action$="/admin/hidden-paths/add"]')->form();
        $client->submit($form, ['path' => 'A - GM']);

        self::ensureKernelShutdown();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $note = $em->getRepository(Note::class)->findOneBySlug('a-gm/secret-plot');
        self::assertNotNull($note);
        self::assertTrue($note->isHidden());

        $client->restart();
        $client->request('GET', '/notes/a-gm/secret-plot');
        self::assertResponseStatusCodeSame(404);
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
