<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthAuthorizationCodeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OAuthAuthorizationCodeRepository::class)]
#[ORM\Table(name: 'oauth_authorization_codes')]
class OAuthAuthorizationCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $codeHash = '';

    #[ORM\Column(length: 64)]
    private string $clientId = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 2048)]
    private string $redirectUri = '';

    #[ORM\Column(length: 128)]
    private string $codeChallenge = '';

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $resource = null;

    #[ORM\Column(length: 255)]
    private string $scope = 'mcp:read';

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(
        string $codeHash,
        string $clientId,
        User $user,
        string $redirectUri,
        string $codeChallenge,
        \DateTimeImmutable $expiresAt,
        ?string $resource = null,
        string $scope = 'mcp:read',
    ) {
        $this->codeHash = $codeHash;
        $this->clientId = $clientId;
        $this->user = $user;
        $this->redirectUri = $redirectUri;
        $this->codeChallenge = $codeChallenge;
        $this->expiresAt = $expiresAt;
        $this->resource = $resource;
        $this->scope = $scope;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function getCodeChallenge(): string
    {
        return $this->codeChallenge;
    }

    public function getResource(): ?string
    {
        return $this->resource;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }
}
