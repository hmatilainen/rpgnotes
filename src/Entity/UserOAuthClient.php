<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserOAuthClientRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserOAuthClientRepository::class)]
#[ORM\Table(name: 'user_oauth_clients')]
class UserOAuthClient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, unique: true)]
    private string $clientId = '';

    #[ORM\Column(length: 64)]
    private string $clientSecretHash = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $redirectUris = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $redirectUris
     */
    public function __construct(User $user, string $clientId, string $clientSecretHash, array $redirectUris)
    {
        $this->user = $user;
        $this->clientId = $clientId;
        $this->clientSecretHash = $clientSecretHash;
        $this->redirectUris = $redirectUris;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): void
    {
        $this->clientId = $clientId;
    }

    public function getClientSecretHash(): string
    {
        return $this->clientSecretHash;
    }

    public function setClientSecretHash(string $clientSecretHash): void
    {
        $this->clientSecretHash = $clientSecretHash;
    }

    /** @return list<string> */
    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }

    /** @param list<string> $redirectUris */
    public function setRedirectUris(array $redirectUris): void
    {
        $this->redirectUris = $redirectUris;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
