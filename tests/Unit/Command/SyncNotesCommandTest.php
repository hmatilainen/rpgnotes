<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SyncNotesCommand;
use App\Service\Vault\GitSyncService;
use App\Service\Vault\IndexResult;
use App\Service\Vault\NoteIndexer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncNotesCommandTest extends TestCase
{
    public function testSyncsAndReportsCounts(): void
    {
        $gitSync = $this->createMock(GitSyncService::class);
        $gitSync->expects(self::once())->method('sync');

        $indexer = $this->createMock(NoteIndexer::class);
        $indexer->expects(self::once())
            ->method('index')
            ->with('/var/vault/repo')
            ->willReturn(new IndexResult(updated: 5, deleted: 1));

        $command = new SyncNotesCommand($gitSync, $indexer, '/var/vault/repo');

        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find('app:sync'));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Indexed 5 notes, removed 1 stale notes.', $tester->getDisplay());
    }
}
