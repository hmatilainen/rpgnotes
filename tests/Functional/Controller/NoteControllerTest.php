<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Note;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NoteControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUpNotes();
        // cleanUpNotes() boots the kernel to get the entity manager; shut it
        // down again so the test method's own createClient() call can boot
        // a fresh kernel (WebTestCase::createClient() refuses to run if the
        // kernel is already booted).
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $this->cleanUpNotes();
        parent::tearDown();
    }

    private function cleanUpNotes(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\Entity\Note')->execute();
        $em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRendersExistingNoteWithOwnHeading(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $note = new Note();
        $note->setVaultPath('Locations/Deerwater.md');
        $note->setSlug('locations/deerwater');
        $note->setTitle('Deerwater');
        $note->setTopLevelFolder('Locations');
        $note->setHtml('<h1>Deerwater</h1><p>A small settlement.</p>');
        $em->persist($note);
        $em->flush();

        $client->request('GET', '/notes/locations/deerwater');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'Deerwater');
        self::assertStringContainsString('A small settlement.', (string) $client->getResponse()->getContent());
    }

    public function testRendersExistingNoteWithoutOwnHeading(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $note = new Note();
        $note->setVaultPath('Locations/Millhaven.md');
        $note->setSlug('locations/millhaven');
        $note->setTitle('Millhaven');
        $note->setTopLevelFolder('Locations');
        $note->setHtml('<p>A quiet farming village.</p>');
        $em->persist($note);
        $em->flush();

        $client->request('GET', '/notes/locations/millhaven');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'h1');
        self::assertSelectorTextContains('h1', 'Millhaven');
        self::assertStringContainsString('A quiet farming village.', (string) $client->getResponse()->getContent());
    }

    public function testReturns404ForUnknownSlug(): void
    {
        $client = static::createClient();
        $client->request('GET', '/notes/does/not/exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testReportNoteShowsPreviousAndNextLinks(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->makeReport($em, 1, 'reports/report-1');
        $this->makeReport($em, 2, 'reports/report-2');
        $this->makeReport($em, 3, 'reports/report-3');

        $client->request('GET', '/notes/reports/report-2');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/notes/reports/report-1" class="report-nav-prev"', $content);
        self::assertStringContainsString('href="/notes/reports/report-3" class="report-nav-next"', $content);
    }

    public function testNewestReportOmitsNextLink(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->makeReport($em, 1, 'reports/report-1');
        $this->makeReport($em, 2, 'reports/report-2');

        $client->request('GET', '/notes/reports/report-2');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('report-nav-prev', $content);
        self::assertStringNotContainsString('report-nav-next', $content);
    }

    public function testOldestReportOmitsPreviousLink(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->makeReport($em, 1, 'reports/report-1');
        $this->makeReport($em, 2, 'reports/report-2');

        $client->request('GET', '/notes/reports/report-1');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('report-nav-prev', $content);
        self::assertStringContainsString('report-nav-next', $content);
    }

    public function testNonReportNoteShowsNoNavigationLinks(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $note = new Note();
        $note->setVaultPath('Locations/Millbrook.md');
        $note->setSlug('locations/millbrook');
        $note->setTitle('Millbrook');
        $note->setTopLevelFolder('Locations');
        $note->setHtml('<p>A quiet crossroads village.</p>');
        $em->persist($note);
        $em->flush();

        $client->request('GET', '/notes/locations/millbrook');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('report-nav-prev', $content);
        self::assertStringNotContainsString('report-nav-next', $content);
    }

    private function makeReport(EntityManagerInterface $em, int $number, string $slug): Note
    {
        $note = new Note();
        $note->setVaultPath('Reports/report-' . $number . '.md');
        $note->setSlug($slug);
        $note->setTitle('Report ' . $number);
        $note->setTopLevelFolder('Reports');
        $note->setHtml('<p>content</p>');
        $note->setReportNumber($number);
        $em->persist($note);
        $em->flush();

        return $note;
    }

    public function testHiddenNoteReturns404ForAnonymousVisitor(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $note = $this->makeHiddenNote($em);

        $client->request('GET', '/notes/a-gm/secret-plot');

        self::assertResponseStatusCodeSame(404);
    }

    public function testHiddenNoteReturns404ForLoggedInPlayer(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->makeHiddenNote($em);
        $player = $this->makeUser($em, 'player-visitor', 'ROLE_PLAYER');

        $client->loginUser($player);
        $client->request('GET', '/notes/a-gm/secret-plot');

        self::assertResponseStatusCodeSame(404);
    }

    public function testHiddenNoteIsVisibleToAdmin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->makeHiddenNote($em);
        $admin = $this->makeUser($em, 'admin-visitor', 'ROLE_ADMIN');

        $client->loginUser($admin);
        $client->request('GET', '/notes/a-gm/secret-plot');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Top secret.');
    }

    private function makeHiddenNote(EntityManagerInterface $em): Note
    {
        $note = new Note();
        $note->setVaultPath('A - GM/Secret Plot.md');
        $note->setSlug('a-gm/secret-plot');
        $note->setTitle('Secret Plot');
        $note->setTopLevelFolder('A - GM');
        $note->setHtml('<p>Top secret.</p>');
        $note->setHidden(true);
        $em->persist($note);
        $em->flush();

        return $note;
    }

    private function makeUser(EntityManagerInterface $em, string $username, string $role): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
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
