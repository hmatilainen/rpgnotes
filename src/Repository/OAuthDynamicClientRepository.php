<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OAuthDynamicClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OAuthDynamicClient>
 */
class OAuthDynamicClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OAuthDynamicClient::class);
    }

    public function findOneByClientId(string $clientId): ?OAuthDynamicClient
    {
        return $this->findOneBy(['clientId' => $clientId]);
    }
}
