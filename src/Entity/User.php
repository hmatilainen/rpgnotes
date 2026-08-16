<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $label = '';

    #[ORM\Column(length: 180, unique: true, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(length: 20)]
    private string $role = 'ROLE_PLAYER';

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $inviteToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $inviteTokenExpiresAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): void
    {
        $this->username = $username;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(?string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }

    public function getInviteToken(): ?string
    {
        return $this->inviteToken;
    }

    public function setInviteToken(?string $inviteToken): void
    {
        $this->inviteToken = $inviteToken;
    }

    public function getInviteTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->inviteTokenExpiresAt;
    }

    public function setInviteTokenExpiresAt(?\DateTimeImmutable $inviteTokenExpiresAt): void
    {
        $this->inviteTokenExpiresAt = $inviteTokenExpiresAt;
    }

    public function isInviteValid(): bool
    {
        return $this->inviteToken !== null
            && $this->inviteTokenExpiresAt !== null
            && $this->inviteTokenExpiresAt > new \DateTimeImmutable();
    }

    public function getRoles(): array
    {
        return [$this->role];
    }

    public function eraseCredentials(): void
    {
        // No plaintext/temporary sensitive data stored on this entity.
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }
}
