<?php

declare(strict_types=1);

namespace App\Service\Vault;

use Symfony\Component\Process\Process;

final class GitPublishService
{
    public function __construct(
        private readonly string $vaultPath,
        private readonly string $repoUrl,
        private readonly string $committerEmail = 'rpgnotes@localhost',
        private readonly string $committerName = 'RPG Notes',
    ) {
    }

    public function syncToRemote(): void
    {
        if (!is_dir($this->vaultPath . '/.git')) {
            $this->run(['git', 'clone', $this->repoUrl, $this->vaultPath]);

            return;
        }

        $this->run(['git', '-C', $this->vaultPath, 'fetch', 'origin']);
        $this->run(['git', '-C', $this->vaultPath, 'reset', '--hard', 'origin/HEAD']);
    }

    public function addAndPush(string $relativeVaultPath, string $content, string $commitMessage): void
    {
        $this->syncToRemote();

        $absolutePath = $this->vaultPath . '/' . $relativeVaultPath;
        $directory = \dirname($absolutePath);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }

        if (file_put_contents($absolutePath, $content) === false) {
            throw new \RuntimeException(sprintf('Unable to write vault file: %s', $absolutePath));
        }

        $this->configureCommitter();
        $this->run(['git', '-C', $this->vaultPath, 'add', $relativeVaultPath]);
        $this->run(['git', '-C', $this->vaultPath, 'commit', '-m', $commitMessage]);
        $this->run(['git', '-C', $this->vaultPath, 'push', 'origin', 'HEAD']);
    }

    private function configureCommitter(): void
    {
        $this->run(['git', '-C', $this->vaultPath, 'config', 'user.email', $this->committerEmail]);
        $this->run(['git', '-C', $this->vaultPath, 'config', 'user.name', $this->committerName]);
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
