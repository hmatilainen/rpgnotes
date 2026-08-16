<?php

declare(strict_types=1);

namespace App\Service\Sidebar;

use App\Entity\Note;

final class SidebarNode
{
    /** @var array<string, SidebarNode> */
    private array $folders = [];

    /** @var Note[] */
    private array $notes = [];

    public function __construct(public readonly string $name)
    {
    }

    public function childFolder(string $name): self
    {
        return $this->folders[$name] ??= new self($name);
    }

    public function addNote(Note $note): void
    {
        $this->notes[] = $note;
    }

    /**
     * @return SidebarNode[]
     */
    public function getFolders(): array
    {
        ksort($this->folders);

        return $this->folders;
    }

    /**
     * @return Note[]
     */
    public function getNotes(): array
    {
        return $this->notes;
    }
}
