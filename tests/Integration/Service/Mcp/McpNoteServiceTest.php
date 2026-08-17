<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Mcp;

use App\Entity\HiddenPath;
use App\Entity\Note;
use App\Entity\User;
use App\Service\Mcp\McpNoteService;
use App\Service\Vault\NoteIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class McpNoteServiceTest extends KernelTestCase
{
    private McpNoteService $mcp;
    private User $player;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->mcp = $container->get(McpNoteService::class);

        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $em->createQuery('DELETE FROM App\Entity\User')->execute();
        $em->createQuery('DELETE FROM '.Note::class)->execute();
        $em->createQuery('DELETE FROM '.HiddenPath::class)->execute();

        $hiddenPath = new HiddenPath();
        $hiddenPath->setPath('A - GM');
        $em->persist($hiddenPath);
        $em->flush();

        $vaultRoot = \dirname(__DIR__, 3).'/Fixtures/vault';
        $container->get(NoteIndexer::class)->index($vaultRoot);

        $this->player = new User();
        $this->player->setLabel('Player');
        $this->player->setUsername('mcp-player');
        $this->player->setRole('ROLE_PLAYER');
        $this->player->setPasswordHash($hasher->hashPassword($this->player, 'password123'));
        $em->persist($this->player);
        $em->flush();
    }

    public function testGetSiteOverview(): void
    {
        $overview = $this->mcp->getSiteOverview($this->player);

        self::assertSame('Forgotten Realms', $overview['campaign']);
        self::assertNotEmpty($overview['folders']);
        self::assertNotNull($overview['newest_report']);
    }

    public function testListSessionReportsIsPaginated(): void
    {
        $page1 = $this->mcp->listSessionReports($this->player, 1, 5);

        self::assertArrayHasKey('page', $page1);
        self::assertArrayHasKey('per_page', $page1);
        self::assertArrayHasKey('total', $page1);
        self::assertArrayHasKey('reports', $page1);
        self::assertNotEmpty($page1['reports']);
        self::assertGreaterThanOrEqual(1, $page1['total']);
    }

    public function testGetSessionReportByNumber(): void
    {
        $report = $this->mcp->getSessionReport($this->player, 1, null);

        self::assertNotNull($report);
        self::assertSame(1, $report['report_number']);
        self::assertNotEmpty($report['markdown']);
    }

    public function testGetSessionReportReturnsNullWhenMissing(): void
    {
        self::assertNull($this->mcp->getSessionReport($this->player, 99999, null));
    }

    public function testGetNoteBySlugAndVaultPath(): void
    {
        $bySlug = $this->mcp->getNote($this->player, 'locations/deerwater', null);
        self::assertNotNull($bySlug);

        $byPath = $this->mcp->getNote($this->player, null, 'Locations/Deerwater');
        self::assertNotNull($byPath);
        self::assertSame($bySlug['slug'], $byPath['slug']);
    }

    public function testSearchNotesFindsTermsInFixtureVault(): void
    {
        $results = $this->mcp->searchNotes($this->player, 'Deerwater', 5, null);

        self::assertNotEmpty($results);
    }

    public function testBrowseVaultRootIsShallow(): void
    {
        $root = $this->mcp->browseVault($this->player, null);
        $encoded = json_encode($root, JSON_THROW_ON_ERROR);

        self::assertLessThan(8000, strlen($encoded), 'Root browse should be shallow, not the full vault tree');
        self::assertArrayHasKey('folders', $root);
        self::assertNotEmpty($root['folders']);

        foreach ($root['folders'] as $folder) {
            self::assertArrayHasKey('name', $folder);
            self::assertArrayHasKey('note_count', $folder);
            self::assertArrayNotHasKey('notes', $folder);
        }
    }

    public function testBrowseVaultFolderListsImmediateChildren(): void
    {
        $people = $this->mcp->browseVault($this->player, 'People');

        self::assertSame('People', $people['name']);
        self::assertArrayHasKey('folders', $people);
        self::assertArrayHasKey('notes', $people);
    }

    public function testHiddenPathsAndNotesAreExcludedFromMcp(): void
    {
        $overview = $this->mcp->getSiteOverview($this->player);
        self::assertNotContains('A - GM', $overview['folders']);

        $root = $this->mcp->browseVault($this->player, null);
        $folderNames = array_column($root['folders'], 'name');
        self::assertNotContains('A - GM', $folderNames);

        self::assertNull($this->mcp->getNote($this->player, 'a-gm/secrets', null));
        self::assertNull($this->mcp->getNote($this->player, null, 'A - GM/Secrets'));

        $hiddenBrowse = $this->mcp->browseVault($this->player, 'A - GM');
        self::assertArrayHasKey('error', $hiddenBrowse);

        $secretHits = $this->mcp->searchNotes($this->player, 'Secrets', 10, null);
        foreach ($secretHits as $hit) {
            self::assertStringNotContainsString('A - GM', $hit['vault_path']);
        }
    }
}
