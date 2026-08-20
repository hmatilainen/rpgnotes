<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthRefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OAuthRefreshTokenRepository::class)]
#[ORM\Table(name: 'oauth_refresh_tokens')]
class OAuthRefreshToken
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OAuthAccessToken $accessToken;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        string $tokenHash,
        User $user,
        string $clientId,
        OAuthAccessToken $accessToken,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->tokenHash = $tokenHash;
        $this->user = $user;
        $this->clientId = $clientId;
        $this->accessToken = $accessToken;
        $this->expiresAt = $expiresAt;
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

    public function getAccessToken(): OAuthAccessToken
    {
        return $this->accessToken;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
    }
}
