<?php

declare(strict_types=1);

namespace App\Service\Vault;

use Symfony\Component\Process\Process;

class GitSyncService
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
        // origin/HEAD tracks whatever branch the remote actually treats as
        // its default (set automatically on clone) — don't assume "main",
        // since plenty of repos (including this one) still default to
        // "master" or something else entirely.
        $this->run(['git', '-C', $this->vaultPath, 'reset', '--hard', 'origin/HEAD']);
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
                $this->redactCredentials(implode(' ', $command)),
                $this->redactCredentials($process->getErrorOutput())
            ));
        }
    }

    private function redactCredentials(string $value): string
    {
        return preg_replace('#(https?://)[^/@\s]+@#', '$1***@', $value) ?? $value;
    }
}
