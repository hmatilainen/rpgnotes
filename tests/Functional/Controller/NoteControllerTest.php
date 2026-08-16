<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
}
