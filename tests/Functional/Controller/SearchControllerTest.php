<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Note;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SearchControllerTest extends WebTestCase
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
    }

    public function testSearchPageFindsPublicNote(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->makeNote($em, 'Locations/Deerwater.md', 'locations/deerwater', 'Deerwater', 'A small settlement by the river.');

        $client->request('GET', '/search?q=Deerwater');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Deerwater', $content);
        self::assertStringContainsString('href="/notes/locations/deerwater"', $content);
    }

    public function testSearchPageEmptyQueryShowsEmptyState(): void
    {
        $client = static::createClient();
        $client->request('GET', '/search');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Enter a search', (string) $client->getResponse()->getContent());
    }

    public function testSearchHidesHiddenNotesFromAnonymous(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->makeNote($em, 'Locations/Deerwater.md', 'locations/deerwater', 'Deerwater', 'Public town.');
        $hidden = $this->makeNote($em, 'A - GM/Secrets.md', 'a-gm/secrets', 'Secrets', 'Hidden plot about Deerwater.');
        $hidden->setHidden(true);
        $em->flush();
        $this->refreshSearchVectors($em);

        $client->request('GET', '/search?q=Deerwater');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/notes/locations/deerwater"', $content);
        self::assertStringNotContainsString('href="/notes/a-gm/secrets"', $content);
    }

    public function testSearchShowsHiddenNotesToAdmin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hidden = $this->makeNote($em, 'A - GM/Secrets.md', 'a-gm/secrets', 'Secrets', 'Hidden plot about Deerwater.');
        $hidden->setHidden(true);
        $em->flush();
        $this->refreshSearchVectors($em);
        $admin = $this->makeUser($em, 'search-admin', 'ROLE_ADMIN');

        $client->loginUser($admin);
        $client->request('GET', '/search?q=Deerwater');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('href="/notes/a-gm/secrets"', (string) $client->getResponse()->getContent());
    }

    public function testSearchJsonReturnsResults(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->makeNote($em, 'Locations/Deerwater.md', 'locations/deerwater', 'Deerwater', 'A small settlement.');

        $client->request('GET', '/search.json?q=Deerwater');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Deerwater', $payload['query']);
        self::assertNotEmpty($payload['results']);
        self::assertSame('Deerwater', $payload['results'][0]['title']);
        self::assertSame('locations/deerwater', $payload['results'][0]['slug']);
        self::assertSame('/notes/locations/deerwater', $payload['results'][0]['url']);
    }

    public function testNavbarIncludesSearchForm(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form.navbar-search');
        self::assertSelectorExists('input[name="q"]');
    }

    private function makeNote(
        EntityManagerInterface $em,
        string $vaultPath,
        string $slug,
        string $title,
        string $body,
    ): Note {
        $note = new Note();
        $note->setVaultPath($vaultPath);
        $note->setSlug($slug);
        $note->setTitle($title);
        $note->setTopLevelFolder(explode('/', $vaultPath)[0]);
        $note->setHtml('<p>'.htmlspecialchars($body, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>');
        $note->setBodyMarkdown($body);
        $em->persist($note);
        $em->flush();
        $this->refreshSearchVectors($em);

        return $note;
    }

    private function refreshSearchVectors(EntityManagerInterface $em): void
    {
        $em->getConnection()->executeStatement(
            "UPDATE notes SET search_vector = to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(body_markdown, ''))"
        );
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
