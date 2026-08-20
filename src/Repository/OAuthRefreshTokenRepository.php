<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthRefreshToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthRefreshToken>
 */
class OAuthRefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthRefreshToken::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?OAuthRefreshToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }
}
