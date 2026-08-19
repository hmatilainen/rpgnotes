<?php

declare(strict_types=1);

namespace App\Service\Vault;

class GitSyncService
{
    public function __construct(
        private readonly VaultGitCheckout $checkout,
    ) {
    }

    public function sync(): void
    {
        $this->checkout->withLock(
            fn () => $this->checkout->fastForwardToRemote(allowUnpushedLocal: false)
        );
    }
}
