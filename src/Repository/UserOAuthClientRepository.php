<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserOAuthClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserOAuthClient>
 */
class UserOAuthClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserOAuthClient::class);
    }

    public function findOneByUser(User $user): ?UserOAuthClient
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function findOneByClientId(string $clientId): ?UserOAuthClient
    {
        return $this->findOneBy(['clientId' => $clientId]);
    }
}
