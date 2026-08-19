<?php

declare(strict_types=1);

namespace App\Service\Vault;

final class GitPublishService
{
    public function __construct(
        private readonly VaultGitCheckout $checkout,
        private readonly string $committerEmail = 'rpgnotes@localhost',
        private readonly string $committerName = 'RPG Notes',
    ) {
    }

    public function syncToRemote(): void
    {
        $this->checkout->withLock(
            fn () => $this->checkout->fastForwardToRemote(allowUnpushedLocal: true)
        );
    }

    public function addAndPush(string $relativeVaultPath, string $content, string $commitMessage): void
    {
        $this->checkout->withLock(function () use ($relativeVaultPath, $content, $commitMessage): void {
            $this->checkout->fastForwardToRemote(allowUnpushedLocal: true);

            if ($this->checkout->vaultFileExists($relativeVaultPath)) {
                throw new \RuntimeException(sprintf(
                    'Vault file already exists: %s',
                    $relativeVaultPath,
                ));
            }

            $absolutePath = $this->checkout->getVaultPath() . '/' . $relativeVaultPath;
            $directory = \dirname($absolutePath);

            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Unable to create directory: %s', $directory));
            }

            if (file_put_contents($absolutePath, $content) === false) {
                throw new \RuntimeException(sprintf('Unable to write vault file: %s', $absolutePath));
            }

            $vaultPath = $this->checkout->getVaultPath();
            $this->configureCommitter();
            $this->checkout->run(['git', '-C', $vaultPath, 'add', $relativeVaultPath]);
            $this->checkout->run(['git', '-C', $vaultPath, 'commit', '-m', $commitMessage]);
            $this->checkout->run(['git', '-C', $vaultPath, 'push', 'origin', 'HEAD']);
        });
    }

    private function configureCommitter(): void
    {
        $vaultPath = $this->checkout->getVaultPath();
        $this->checkout->run(['git', '-C', $vaultPath, 'config', 'user.email', $this->committerEmail]);
        $this->checkout->run(['git', '-C', $vaultPath, 'config', 'user.name', $this->committerName]);
    }
}
