<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserApiToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserApiToken>
 */
class UserApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserApiToken::class);
    }

    public function findOneByUser(User $user): ?UserApiToken
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function findOneByTokenHash(string $tokenHash): ?UserApiToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }
}
