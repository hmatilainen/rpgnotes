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
}
