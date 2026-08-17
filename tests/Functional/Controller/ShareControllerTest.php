<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Note;
use App\Entity\ShareToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShareControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        if (static::$booted ?? false) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->createQuery('DELETE FROM App\Entity\ShareToken')->execute();
            $em->createQuery('DELETE FROM App\Entity\Note')->execute();
        }

        parent::tearDown();
    }

    public function testShareLinkShowsReportWithoutSidebar(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $note = new Note();
        $note->setVaultPath('Reports/1-10/Report-1 1.1.1367 Start.md');
        $note->setSlug('reports/report-1-start');
        $note->setTitle('Report-1 1.1.1367 Start');
        $note->setTopLevelFolder('Reports');
        $note->setHtml('<p>We began.</p>');
        $note->setReportNumber(1);
        $note->setSessionDate(new \DateTimeImmutable('1367-01-01'));
        $note->setUpdatedAt(new \DateTimeImmutable());
        $em->persist($note);
        $em->flush();

        $token = new ShareToken($note, 'abc123deadbeef');
        $em->persist($token);
        $em->flush();

        $client->request('GET', '/share/abc123deadbeef');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('article', 'We began.');
        self::assertSelectorNotExists('.sidebar');
    }
}
