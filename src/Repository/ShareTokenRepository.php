<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ShareToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShareToken>
 */
class ShareTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShareToken::class);
    }

    public function findOneByToken(string $token): ?ShareToken
    {
        return $this->findOneBy(['token' => $token]);
    }
}
