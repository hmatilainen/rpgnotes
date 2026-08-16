<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Note;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Note>
 */
class NoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Note::class);
    }

    public function findOneByVaultPath(string $vaultPath): ?Note
    {
        return $this->findOneBy(['vaultPath' => $vaultPath]);
    }

    /**
     * @param string[] $vaultPaths
     * @return Note[]
     */
    public function findByVaultPathNotIn(array $vaultPaths): array
    {
        $qb = $this->createQueryBuilder('n');

        if ($vaultPaths === []) {
            return $qb->getQuery()->getResult();
        }

        return $qb
            ->where($qb->expr()->notIn('n.vaultPath', ':paths'))
            ->setParameter('paths', $vaultPaths)
            ->getQuery()
            ->getResult();
    }

    public function findOneBySlug(string $slug): ?Note
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return Note[]
     */
    public function findAllForSidebar(): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.topLevelFolder != :reports')
            ->setParameter('reports', 'Reports')
            ->orderBy('n.vaultPath', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Always excludes the single newest report (see findNewestReport()),
     * regardless of page — the caller renders that one separately as the
     * front page's featured report.
     *
     * @return Note[]
     */
    public function findReportsPaginated(int $page, int $perPage): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->orderBy('n.reportNumber', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage + 1)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countReports(): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.reportNumber IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findNewestReport(): ?Note
    {
        return $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->orderBy('n.reportNumber', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findPreviousReport(int $reportNumber): ?Note
    {
        return $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->andWhere('n.reportNumber < :reportNumber')
            ->setParameter('reportNumber', $reportNumber)
            ->orderBy('n.reportNumber', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findNextReport(int $reportNumber): ?Note
    {
        return $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->andWhere('n.reportNumber > :reportNumber')
            ->setParameter('reportNumber', $reportNumber)
            ->orderBy('n.reportNumber', 'ASC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
