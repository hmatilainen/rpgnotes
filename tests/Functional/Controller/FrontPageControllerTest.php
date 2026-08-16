<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FrontPageControllerTest extends WebTestCase
{
    public function testListsReportsNewestFirstAndExcludesNonReports(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $older = $this->makeReport($em, 1, 'Reports/1-10/Report-1 x.md', 'report-1');
        $newer = $this->makeReport($em, 2, 'Reports/1-10/Report-2 y.md', 'report-2');
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
        $content = (string) $client->getResponse()->getContent();
        // Sanity: ensure 'report-2' string exists before the ordering check below.
        self::assertStringContainsString('report-2', $content);
        self::assertTrue(strpos($content, 'report-2') < strpos($content, 'report-1'));
        self::assertStringNotContainsString('Summary', $content);

        foreach ([$older, $newer, $summary] as $note) {
            $em->remove($note);
        }
        $em->flush();
    }

    private function makeReport(EntityManagerInterface $em, int $number, string $vaultPath, string $slug): Note
    {
        $note = new Note();
        $note->setVaultPath($vaultPath);
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
