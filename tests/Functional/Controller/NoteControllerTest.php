<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NoteControllerTest extends WebTestCase
{
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
        self::assertSelectorTextContains('h1', 'Deerwater');
        self::assertStringContainsString('A small settlement.', (string) $client->getResponse()->getContent());

        $em->remove($note);
        $em->flush();
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
        self::assertSelectorTextContains('h1', 'Millhaven');
        self::assertStringContainsString('A quiet farming village.', (string) $client->getResponse()->getContent());

        $em->remove($note);
        $em->flush();
    }

    public function testReturns404ForUnknownSlug(): void
    {
        $client = static::createClient();
        $client->request('GET', '/notes/does/not/exist');

        self::assertResponseStatusCodeSame(404);
    }
}
