<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthAccessToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthAccessToken>
 */
class OAuthAccessTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthAccessToken::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?OAuthAccessToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }
}
