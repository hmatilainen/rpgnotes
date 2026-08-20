<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthAccessTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OAuthAccessTokenRepository::class)]
#[ORM\Table(name: 'oauth_access_tokens')]
class OAuthAccessToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $clientId = '';

    #[ORM\Column(length: 255)]
    private string $scope = 'mcp:read';

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $tokenHash,
        User $user,
        string $clientId,
        \DateTimeImmutable $expiresAt,
        string $scope = 'mcp:read',
    ) {
        $this->tokenHash = $tokenHash;
        $this->user = $user;
        $this->clientId = $clientId;
        $this->expiresAt = $expiresAt;
        $this->scope = $scope;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
