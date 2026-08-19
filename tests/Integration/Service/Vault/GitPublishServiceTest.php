<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Vault;

use App\Service\Vault\GitPublishService;
use App\Service\Vault\VaultGitCheckout;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitPublishServiceTest extends TestCase
{
    private string $bareRepoPath;
    private string $checkoutPath;
    private GitPublishService $service;

    protected function setUp(): void
    {
        $this->bareRepoPath = sys_get_temp_dir() . '/rpgnotes-publish-bare-' . uniqid();
        $this->checkoutPath = sys_get_temp_dir() . '/rpgnotes-publish-checkout-' . uniqid();

        (new Process(['git', 'init', '--bare', $this->bareRepoPath]))->mustRun();

        $seedPath = sys_get_temp_dir() . '/rpgnotes-publish-seed-' . uniqid();
        (new Process(['git', 'clone', $this->bareRepoPath, $seedPath]))->mustRun();
        mkdir($seedPath . '/Reports/1-10', 0777, true);
        file_put_contents($seedPath . '/Reports/1-10/Report-1 1.1.1367 Start.md', '# Start');
        (new Process(['git', '-C', $seedPath, 'config', 'user.email', 'test@example.com']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'config', 'user.name', 'Test']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'add', '.']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'commit', '-m', 'initial']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'branch', '-M', 'main']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'push', 'origin', 'main']))->mustRun();
        (new Process(['git', '-C', $this->bareRepoPath, 'symbolic-ref', 'HEAD', 'refs/heads/main']))->mustRun();

        $this->service = new GitPublishService(new VaultGitCheckout($this->checkoutPath, $this->bareRepoPath));
    }

    public function testAddsFileAndPushesToRemote(): void
    {
        $relativePath = 'Reports/1-10/Report-2 16.8.1367 New session.md';
        $content = "---\npublished_at: 2026-08-16T20:00:00+01:00\n---\n\n## New session\n\nBody\n";

        $this->service->addAndPush($relativePath, $content, 'Add session report #2');

        self::assertFileExists($this->checkoutPath . '/' . $relativePath);

        $verifyClone = sys_get_temp_dir() . '/rpgnotes-publish-verify-' . uniqid();
        (new Process(['git', 'clone', $this->bareRepoPath, $verifyClone]))->mustRun();
        self::assertFileExists($verifyClone . '/' . $relativePath);
    }

    public function testDoesNotOverwriteExistingVaultFile(): void
    {
        $relativePath = 'Reports/1-10/Report-2 16.8.1367 New session.md';
        $existing = "---\n---\n\n## Existing\n\nOriginal content\n";
        $replacement = "---\n---\n\n## Replacement\n\nNew content\n";

        $this->service->addAndPush($relativePath, $existing, 'Add session report #2');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vault file already exists');

        $this->service->addAndPush($relativePath, $replacement, 'Overwrite session report #2');
    }

    public function testSyncPreservesUnpushedLocalCommit(): void
    {
        $relativePath = 'Reports/1-10/Report-2 16.8.1367 Unpushed.md';
        $content = "---\n---\n\n## Unpushed\n\nBody\n";

        $checkout = new VaultGitCheckout($this->checkoutPath, $this->bareRepoPath);
        $checkout->fastForwardToRemote(allowUnpushedLocal: true);

        $absolutePath = $this->checkoutPath . '/' . $relativePath;
        mkdir(\dirname($absolutePath), 0777, true);
        file_put_contents($absolutePath, $content);
        $checkout->run(['git', '-C', $this->checkoutPath, 'config', 'user.email', 'test@example.com']);
        $checkout->run(['git', '-C', $this->checkoutPath, 'config', 'user.name', 'Test']);
        $checkout->run(['git', '-C', $this->checkoutPath, 'add', $relativePath]);
        $checkout->run(['git', '-C', $this->checkoutPath, 'commit', '-m', 'local only']);

        $this->service->syncToRemote();

        self::assertFileExists($absolutePath);
        self::assertStringContainsString('Unpushed', (string) file_get_contents($absolutePath));
    }
}
