<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HiddenPath;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HiddenPath>
 */
class HiddenPathRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HiddenPath::class);
    }

    /**
     * @return string[]
     */
    public function findAllPaths(): array
    {
        return array_map(
            static fn (HiddenPath $hiddenPath) => $hiddenPath->getPath(),
            $this->findBy([], ['path' => 'ASC'])
        );
    }
}
