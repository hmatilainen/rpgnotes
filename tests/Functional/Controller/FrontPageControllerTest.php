<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FrontPageControllerTest extends WebTestCase
{
    public function testPage1ShowsFeaturedNewestReportInFullAndExcludesItFromTheListBelow(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $report1 = $this->makeReport($em, 1, 'report-1', '<p>First session content.</p>');
        $report2 = $this->makeReport($em, 2, 'report-2', '<p>Second session content.</p>');
        $report3 = $this->makeReport($em, 3, 'report-3', '<p>Third session content.</p>');

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

        foreach ([$report1, $report2, $report3] as $note) {
            $em->remove($note);
        }
        $em->flush();
    }

    public function testFeaturedReportLinksToThePreviousSession(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $report1 = $this->makeReport($em, 1, 'report-1', '<p>First.</p>');
        $report2 = $this->makeReport($em, 2, 'report-2', '<p>Second.</p>');

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('href="/notes/report-1" class="report-nav-prev"', $content);

        foreach ([$report1, $report2] as $note) {
            $em->remove($note);
        }
        $em->flush();
    }

    public function testExcludesNonReportNotes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $report = $this->makeReport($em, 1, 'report-1', '<p>content</p>');
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

        foreach ([$report, $summary] as $note) {
            $em->remove($note);
        }
        $em->flush();
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
