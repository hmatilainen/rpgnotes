<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Note;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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
    public function findAllForSidebar(bool $includeHidden = false): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.topLevelFolder != :reports')
            ->setParameter('reports', 'Reports')
            ->orderBy('n.vaultPath', 'ASC');

        if (!$includeHidden) {
            $qb->andWhere('n.hidden = false');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Paginated session reports newest-first.
     *
     * @param bool $skipFeaturedReport When true, excludes the newest report (front-page featured slot).
     *
     * @return Note[]
     */
    public function findReportsPaginated(int $page, int $perPage, bool $includeHidden = false, bool $skipFeaturedReport = true): array
    {
        $offset = ($page - 1) * $perPage + ($skipFeaturedReport ? 1 : 0);

        $qb = $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->orderBy('n.reportNumber', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage);

        if (!$includeHidden) {
            $qb->andWhere('n.hidden = false');
        }

        return $qb->getQuery()->getResult();
    }

    public function countReports(bool $includeHidden = false): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.reportNumber IS NOT NULL');

        if (!$includeHidden) {
            $qb->andWhere('n.hidden = false');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findNewestReport(bool $includeHidden = false): ?Note
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->orderBy('n.reportNumber', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults(1);

        if (!$includeHidden) {
            $qb->andWhere('n.hidden = false');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findPreviousReport(int $reportNumber, bool $includeHidden = false): ?Note
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->andWhere('n.reportNumber < :reportNumber')
            ->setParameter('reportNumber', $reportNumber)
            ->orderBy('n.reportNumber', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults(1);

        if (!$includeHidden) {
            $qb->andWhere('n.hidden = false');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findNextReport(int $reportNumber, bool $includeHidden = false): ?Note
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->andWhere('n.reportNumber > :reportNumber')
            ->setParameter('reportNumber', $reportNumber)
            ->orderBy('n.reportNumber', 'ASC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults(1);

        if (!$includeHidden) {
            $qb->andWhere('n.hidden = false');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return Note[]
     */
    public function searchByText(string $query, bool $includeHidden, int $limit, ?string $folder): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT n.id FROM notes n WHERE n.search_vector @@ plainto_tsquery(\'simple\', :q)';
        $params = ['q' => $query];

        if (!$includeHidden) {
            $sql .= ' AND n.hidden = false';
        }

        if ($folder !== null && $folder !== '') {
            $sql .= ' AND n.vault_path LIKE :folder_prefix';
            $params['folder_prefix'] = rtrim($folder, '/') . '/%';
        }

        $sql .= ' ORDER BY ts_rank(n.search_vector, plainto_tsquery(\'simple\', :q)) DESC LIMIT :limit';

        $types = ['q' => ParameterType::STRING, 'limit' => ParameterType::INTEGER];
        if (isset($params['folder_prefix'])) {
            $types['folder_prefix'] = ParameterType::STRING;
        }

        $ids = $conn->fetchFirstColumn($sql, array_merge($params, ['limit' => $limit]), $types);

        if ($ids === []) {
            return [];
        }

        $notes = $this->findBy(['id' => $ids]);
        $byId = [];
        foreach ($notes as $note) {
            $byId[$note->getId()] = $note;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[(int) $id])) {
                $ordered[] = $byId[(int) $id];
            }
        }

        return $ordered;
    }

    /**
     * @return string[]
     */
    public function findDistinctTopLevelFolders(bool $includeHidden = false): array
    {
        $qb = $this->createQueryBuilder('n')
            ->select('DISTINCT n.topLevelFolder')
            ->orderBy('n.topLevelFolder', 'ASC');

        if (!$includeHidden) {
            $qb->andWhere('n.hidden = false');
        }

        return array_column($qb->getQuery()->getScalarResult(), 'topLevelFolder');
    }
}
