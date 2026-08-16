<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Vault\GitSyncService;
use App\Service\Vault\NoteIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:sync', description: 'Pull the notes repo from GitHub and reindex it')]
final class SyncNotesCommand extends Command
{
    public function __construct(
        private readonly GitSyncService $gitSync,
        private readonly NoteIndexer $indexer,
        private readonly string $vaultPath,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->gitSync->sync();
        $result = $this->indexer->index($this->vaultPath);

        $output->writeln(sprintf(
            'Indexed %d notes, removed %d stale notes.',
            $result->updated,
            $result->deleted
        ));

        return Command::SUCCESS;
    }
}
