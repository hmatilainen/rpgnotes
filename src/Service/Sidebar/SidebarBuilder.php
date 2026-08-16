<?php

declare(strict_types=1);

namespace App\Service\Sidebar;

use App\Repository\NoteRepository;

final class SidebarBuilder
{
    public function __construct(private readonly NoteRepository $notes)
    {
    }

    public function build(bool $includeHidden = false): SidebarNode
    {
        $root = new SidebarNode('');

        foreach ($this->notes->findAllForSidebar($includeHidden) as $note) {
            $segments = explode('/', $note->getVaultPath());
            array_pop($segments); // drop filename, keep only folder segments

            $cursor = $root;
            foreach ($segments as $segment) {
                $cursor = $cursor->childFolder($segment);
            }

            $cursor->addNote($note);
        }

        return $root;
    }
}
