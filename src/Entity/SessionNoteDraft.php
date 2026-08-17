<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SessionNoteDraftRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SessionNoteDraftRepository::class)]
#[ORM\Table(name: 'session_note_drafts')]
class SessionNoteDraft
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $sessionDate;

    #[ORM\Column(type: 'text')]
    private string $body = '';

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $author)
    {
        $this->author = $author;
        $this->sessionDate = new \DateTimeImmutable('today');
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getSessionDate(): \DateTimeImmutable
    {
        return $this->sessionDate;
    }

    public function setSessionDate(\DateTimeImmutable $sessionDate): void
    {
        $this->sessionDate = $sessionDate;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
