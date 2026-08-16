<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Note;
use App\Repository\NoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NoteRepositoryTest extends KernelTestCase
{
    private NoteRepository $notes;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->notes = $container->get(NoteRepository::class);
        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM App\Entity\Note')->execute();

        // Reports 1, 2, 4, 5, 8 — two gaps (3, and 6-7) to prove
        // Previous/Next skip gaps, and enough rows to prove pagination
        // always excludes the single newest report (8) regardless of page.
        foreach ([1, 2, 4, 5, 8] as $reportNumber) {
            $this->persistReport($reportNumber);
        }
        $this->persistNonReport();

        $this->em->flush();
    }

    public function testFindNewestReportReturnsHighestReportNumber(): void
    {
        self::assertSame(8, $this->notes->findNewestReport()?->getReportNumber());
    }

    public function testFindPreviousReportSkipsGaps(): void
    {
        self::assertSame(5, $this->notes->findPreviousReport(8)?->getReportNumber());
        self::assertSame(4, $this->notes->findPreviousReport(5)?->getReportNumber());
        self::assertSame(2, $this->notes->findPreviousReport(4)?->getReportNumber());
        self::assertSame(1, $this->notes->findPreviousReport(2)?->getReportNumber());
        self::assertNull($this->notes->findPreviousReport(1));
    }

    public function testFindNextReportSkipsGaps(): void
    {
        self::assertSame(2, $this->notes->findNextReport(1)?->getReportNumber());
        self::assertSame(4, $this->notes->findNextReport(2)?->getReportNumber());
        self::assertSame(5, $this->notes->findNextReport(4)?->getReportNumber());
        self::assertSame(8, $this->notes->findNextReport(5)?->getReportNumber());
        self::assertNull($this->notes->findNextReport(8));
    }

    public function testFindReportsPaginatedAlwaysExcludesTheSingleNewestReport(): void
    {
        $page1 = array_map(static fn (Note $n) => $n->getReportNumber(), $this->notes->findReportsPaginated(1, 2));
        $page2 = array_map(static fn (Note $n) => $n->getReportNumber(), $this->notes->findReportsPaginated(2, 2));
        $page3 = array_map(static fn (Note $n) => $n->getReportNumber(), $this->notes->findReportsPaginated(3, 2));

        self::assertSame([5, 4], $page1);
        self::assertSame([2, 1], $page2);
        self::assertSame([], $page3);
    }

    private function persistReport(int $reportNumber): void
    {
        $note = new Note();
        $note->setVaultPath(sprintf('Reports/report-%d.md', $reportNumber));
        $note->setSlug(sprintf('reports/report-%d', $reportNumber));
        $note->setTitle('Report ' . $reportNumber);
        $note->setTopLevelFolder('Reports');
        $note->setHtml('<p>content</p>');
        $note->setReportNumber($reportNumber);
        $this->em->persist($note);
    }

    private function persistNonReport(): void
    {
        $note = new Note();
        $note->setVaultPath('People/Malekith.md');
        $note->setSlug('people/malekith');
        $note->setTitle('Malekith');
        $note->setTopLevelFolder('People');
        $note->setHtml('<p>content</p>');
        $this->em->persist($note);
    }

    protected function tearDown(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Note')->execute();
        parent::tearDown();
    }
}
