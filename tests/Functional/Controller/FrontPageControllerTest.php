<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FrontPageControllerTest extends WebTestCase
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

    public function testPage1ShowsFeaturedNewestReportInFullAndExcludesItFromTheListBelow(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->makeReport($em, 1, 'report-1', '<p>First session content.</p>');
        $this->makeReport($em, 2, 'report-2', '<p>Second session content.</p>');
        $this->makeReport($em, 3, 'report-3', '<p>Third session content.</p>');

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Third session content.', $content);
        self::assertStringNotContainsString('href="/notes/report-3"', $content);
        self::assertStringContainsString('href="/notes/report-2"', $content);
        self::assertStringContainsString('href="/notes/report-1"', $content);
        self::assertTrue(
            strpos($content, 'href="/notes/report-2"') < strpos($content, 'href="/notes/report-1"')
        );
    }

    public function testFeaturedReportLinksToThePreviousSession(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->makeReport($em, 1, 'report-1', '<p>First.</p>');
        $this->makeReport($em, 2, 'report-2', '<p>Second.</p>');

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('href="/notes/report-1" class="report-nav-prev"', $content);
    }

    public function testExcludesNonReportNotes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->makeReport($em, 1, 'report-1', '<p>content</p>');
        $summary = new Note();
        $summary->setVaultPath('Reports/summary.md');
        $summary->setSlug('reports/summary');
        $summary->setTitle('Summary');
        $summary->setTopLevelFolder('Reports');
        $summary->setHtml('<p>summary</p>');
        $em->persist($summary);
        $em->flush();

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Summary', (string) $client->getResponse()->getContent());
    }

    /**
     * Fixture: 23 reports numbered 1..23.
     *
     * findNewestReport() picks report 23 as the featured report, so the
     * paginated list (findReportsPaginated, DESC by reportNumber) only ever
     * considers reports 1..22 as "list items" once the newest is skipped:
     *   listTotal = max(0, total - 1) = max(0, 23 - 1) = 22
     *   totalPages = ceil(22 / 20) = 2
     *
     * findReportsPaginated() queries ALL reports DESC (23, 22, ..., 1) and
     * applies offset = (page - 1) * perPage + 1:
     *   page 1: offset = 1  -> skip {23}, take 20            -> reports 22..3
     *   page 2: offset = 21 -> skip {23, 22, ..., 3} (21 items), take 20
     *                       -> only reports 2 and 1 remain    -> reports 2, 1
     */
    public function testPage2RendersNoFeaturedBlockAndStartsAtTheCorrectOffset(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        for ($i = 1; $i <= 23; $i++) {
            $this->makeReport($em, $i, 'report-' . $i, '<p>Session ' . $i . ' content.</p>');
        }

        $client->request('GET', '/?page=2');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('class="featured-report"', $content);
        self::assertStringContainsString('href="/notes/report-2"', $content);
        self::assertStringContainsString('href="/notes/report-1"', $content);
        self::assertStringNotContainsString('href="/notes/report-3"', $content);
        self::assertTrue(
            strpos($content, 'href="/notes/report-2"') < strpos($content, 'href="/notes/report-1"')
        );
        self::assertStringContainsString('Page 2 of 2', $content);
    }

    private function makeReport(EntityManagerInterface $em, int $number, string $slug, string $html): Note
    {
        $note = new Note();
        $note->setVaultPath('Reports/report-' . $number . '.md');
        $note->setSlug($slug);
        $note->setTitle('Report ' . $number);
        $note->setTopLevelFolder('Reports');
        $note->setHtml($html);
        $note->setReportNumber($number);
        $em->persist($note);
        $em->flush();

        return $note;
    }
}
