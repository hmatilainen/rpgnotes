<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NoteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteRepository::class)]
#[ORM\Table(name: 'notes')]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 1024, unique: true)]
    private string $vaultPath = '';

    #[ORM\Column(length: 1024, unique: true)]
    private string $slug = '';

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(length: 255)]
    private string $topLevelFolder = '';

    #[ORM\Column(type: 'text')]
    private string $html = '';

    #[ORM\Column(nullable: true)]
    private ?int $reportNumber = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $sessionDate = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVaultPath(): string
    {
        return $this->vaultPath;
    }

    public function setVaultPath(string $vaultPath): void
    {
        $this->vaultPath = $vaultPath;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getTopLevelFolder(): string
    {
        return $this->topLevelFolder;
    }

    public function setTopLevelFolder(string $topLevelFolder): void
    {
        $this->topLevelFolder = $topLevelFolder;
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function setHtml(string $html): void
    {
        $this->html = $html;
    }

    public function getReportNumber(): ?int
    {
        return $this->reportNumber;
    }

    public function setReportNumber(?int $reportNumber): void
    {
        $this->reportNumber = $reportNumber;
    }

    public function getSessionDate(): ?\DateTimeImmutable
    {
        return $this->sessionDate;
    }

    public function setSessionDate(?\DateTimeImmutable $sessionDate): void
    {
        $this->sessionDate = $sessionDate;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
