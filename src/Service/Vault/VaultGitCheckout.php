<?php

declare(strict_types=1);

namespace App\Service\Vault;

use Symfony\Component\Process\Process;

/**
 * Shared git checkout for the vault: fetch/reset, command runner, and locking.
 */
final class VaultGitCheckout
{
    public function __construct(
        private readonly string $vaultPath,
        private readonly string $repoUrl,
    ) {
    }

    public function getVaultPath(): string
    {
        return $this->vaultPath;
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withLock(callable $callback): mixed
    {
        $lockPath = $this->lockFilePath();
        $directory = \dirname($lockPath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create lock directory: %s', $directory));
        }

        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open vault git lock: %s', $lockPath));
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire vault git lock.');
            }

            return $callback();
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Fetches from origin and fast-forwards to origin/HEAD when safe.
     *
     * When $allowUnpushedLocal is false (webhook/cron sync), aborts if the local
     * branch has commits not on the remote so unpushed work is never discarded.
     * When true (publish prefetch), leaves local commits in place so a failed
     * push can be retried.
     */
    public function fastForwardToRemote(bool $allowUnpushedLocal = false): void
    {
        if (!is_dir($this->vaultPath . '/.git')) {
            $this->run(['git', 'clone', $this->repoUrl, $this->vaultPath]);

            return;
        }

        $this->run(['git', '-C', $this->vaultPath, 'fetch', 'origin']);

        if ($this->countCommitsAheadOfRemote() > 0) {
            if (!$allowUnpushedLocal) {
                throw new \RuntimeException(
                    'Local vault has unpushed commits; sync aborted to avoid data loss.'
                );
            }

            return;
        }

        $this->run(['git', '-C', $this->vaultPath, 'reset', '--hard', 'origin/HEAD']);
    }

    public function vaultFileExists(string $relativeVaultPath): bool
    {
        return is_file($this->vaultPath . '/' . $relativeVaultPath);
    }

    /**
     * @param string[] $command
     */
    public function run(array $command): void
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

    private function countCommitsAheadOfRemote(): int
    {
        $process = new Process(['git', '-C', $this->vaultPath, 'rev-list', '--count', 'origin/HEAD..HEAD']);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return 0;
        }

        return (int) trim($process->getOutput());
    }

    private function lockFilePath(): string
    {
        if (is_dir($this->vaultPath . '/.git')) {
            return $this->vaultPath . '/.git/vault-operation.lock';
        }

        // Keep the lock beside the vault path so clone can create an empty destination.
        $parent = \dirname($this->vaultPath);

        return $parent . '/.' . basename($this->vaultPath) . '.vault-git.lock';
    }

    private function redactCredentials(string $value): string
    {
        return preg_replace('#(https?://)[^/@\s]+@#', '$1***@', $value) ?? $value;
    }
}
