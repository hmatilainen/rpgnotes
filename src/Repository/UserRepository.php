<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => $username]);
    }

    public function findOneByInviteToken(string $token): ?User
    {
        return $this->findOneBy(['inviteToken' => $token]);
    }

    /**
     * @return User[]
     */
    public function findAllPlayers(): array
    {
        return $this->findBy(['role' => 'ROLE_PLAYER'], ['label' => 'ASC']);
    }
}
