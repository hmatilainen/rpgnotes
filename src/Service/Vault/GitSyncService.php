<?php

declare(strict_types=1);

namespace App\Service\Vault;

use Symfony\Component\Process\Process;

final class GitSyncService
{
    public function __construct(
        private readonly string $vaultPath,
        private readonly string $repoUrl,
    ) {
    }

    public function sync(): void
    {
        if (!is_dir($this->vaultPath . '/.git')) {
            $this->run(['git', 'clone', $this->repoUrl, $this->vaultPath]);

            return;
        }

        $this->run(['git', '-C', $this->vaultPath, 'fetch', 'origin']);
        $this->run(['git', '-C', $this->vaultPath, 'reset', '--hard', 'origin/main']);
    }

    /**
     * @param string[] $command
     */
    private function run(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'Git command "%s" failed: %s',
                implode(' ', $command),
                $process->getErrorOutput()
            ));
        }
    }
}
