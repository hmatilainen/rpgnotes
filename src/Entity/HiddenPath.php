<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HiddenPathRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HiddenPathRepository::class)]
#[ORM\Table(name: 'hidden_paths')]
class HiddenPath
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 1024, unique: true)]
    private string $path = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
    }
}
