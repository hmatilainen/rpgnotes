<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Vault;

use App\Repository\NoteRepository;
use App\Service\Vault\NoteIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NoteIndexerTest extends KernelTestCase
{
    private NoteIndexer $indexer;
    private NoteRepository $notes;
    private EntityManagerInterface $em;
    private string $vaultRoot;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->indexer = $container->get(NoteIndexer::class);
        $this->notes = $container->get(NoteRepository::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->vaultRoot = \dirname(__DIR__, 3) . '/Fixtures/vault';

        // Truncate between tests so runs are independent.
        $this->em->createQuery('DELETE FROM App\Entity\Note')->execute();
    }

    public function testIndexesVisibleNotesAndExcludesHiddenFolder(): void
    {
        $result = $this->indexer->index($this->vaultRoot);

        self::assertSame(4, $result->updated); // Malekith, Deerwater, Report-1, summary
        self::assertSame(0, $result->deleted);
        self::assertNull($this->notes->findOneByVaultPath('A - GM/Secrets.md'));
        self::assertNotNull($this->notes->findOneByVaultPath('People/Malekith.md'));
    }

    public function testStripsFrontmatterCalloutsAndImagePlaceholders(): void
    {
        $this->indexer->index($this->vaultRoot);

        $note = $this->notes->findOneByVaultPath('People/Malekith.md');

        self::assertNotNull($note);
        self::assertStringNotContainsString('type: npc', $note->getHtml());
        self::assertStringNotContainsString('Zhentarim', $note->getHtml());
        self::assertStringNotContainsString('[img:', $note->getHtml());
    }

    public function testResolvesWikilinksAndDropsLinkToHiddenNote(): void
    {
        $this->indexer->index($this->vaultRoot);

        $note = $this->notes->findOneByVaultPath('People/Malekith.md');

        self::assertNotNull($note);
        self::assertStringContainsString('href="/notes/locations/deerwater"', $note->getHtml());
        self::assertStringNotContainsString('href="/notes/a - gm/secrets"', $note->getHtml());
        self::assertStringContainsString('Secrets', $note->getHtml()); // plain text, not a link
    }

    public function testSetsReportNumberOnlyForReportFiles(): void
    {
        $this->indexer->index($this->vaultRoot);

        $report = $this->notes->findOneByVaultPath('Reports/1-10/Report-1 1.1.1367 The Beginning.md');
        $nonReport = $this->notes->findOneByVaultPath('Reports/Tähän mennessä tapahtunutta.md');

        self::assertSame(1, $report->getReportNumber());
        self::assertNull($nonReport->getReportNumber());
    }

    public function testRemovesStaleNotesNoLongerOnDisk(): void
    {
        $this->indexer->index($this->vaultRoot);
        self::assertNotNull($this->notes->findOneByVaultPath('People/Malekith.md'));

        // Point at a smaller vault subset to simulate a file being deleted upstream.
        $reducedVault = sys_get_temp_dir() . '/rpgnotes-reduced-vault';
        if (!is_dir($reducedVault)) {
            mkdir($reducedVault . '/People', 0777, true);
            copy($this->vaultRoot . '/People/Malekith.md', $reducedVault . '/People/Malekith.md');
        }

        $result = $this->indexer->index($reducedVault);

        self::assertSame(1, $result->updated);
        self::assertGreaterThanOrEqual(1, $result->deleted);
        self::assertNull($this->notes->findOneByVaultPath('Locations/Deerwater.md'));
    }

    public function testResolvesSlugCollisionsDeterministically(): void
    {
        $vault = sys_get_temp_dir() . '/rpgnotes-collision-vault-' . uniqid();
        mkdir($vault . '/People', 0777, true);
        // These two filenames differ only in whitespace that the slugifier
        // collapses, so they would naturally collide on "people/foo-bar".
        $paths = ['People/Foo  Bar.md', 'People/Foo Bar.md'];
        sort($paths);
        foreach ($paths as $path) {
            file_put_contents($vault . '/' . $path, "# Foo Bar\n");
        }

        $result = $this->indexer->index($vault);

        self::assertSame(2, $result->updated);

        $first = $this->notes->findOneByVaultPath($paths[0]);
        $second = $this->notes->findOneByVaultPath($paths[1]);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame('people/foo-bar', $first->getSlug());
        self::assertSame('people/foo-bar-2', $second->getSlug());
    }

    public function testFallsBackToStableSlugForUnslugifiableFilename(): void
    {
        $vault = sys_get_temp_dir() . '/rpgnotes-emptyseg-vault-' . uniqid();
        mkdir($vault . '/People', 0777, true);
        file_put_contents($vault . '/People/🎲.md', "# Dice\n");

        $result = $this->indexer->index($vault);

        self::assertSame(1, $result->updated);

        $note = $this->notes->findOneByVaultPath('People/🎲.md');

        self::assertNotNull($note);
        self::assertMatchesRegularExpression('#^people/untitled-[0-9a-f]{8}$#', $note->getSlug());
    }

    protected function tearDown(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Note')->execute();
        parent::tearDown();
    }
}
