<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Vault;

use App\Service\Vault\GitSyncService;
use App\Service\Vault\VaultGitCheckout;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitSyncServiceTest extends TestCase
{
    private string $bareRepoPath;
    private string $checkoutPath;
    private GitSyncService $service;

    protected function setUp(): void
    {
        $this->bareRepoPath = sys_get_temp_dir() . '/rpgnotes-bare-' . uniqid();
        $this->checkoutPath = sys_get_temp_dir() . '/rpgnotes-checkout-' . uniqid();

        (new Process(['git', 'init', '--bare', $this->bareRepoPath]))->mustRun();

        $seedPath = sys_get_temp_dir() . '/rpgnotes-seed-' . uniqid();
        (new Process(['git', 'clone', $this->bareRepoPath, $seedPath]))->mustRun();
        file_put_contents($seedPath . '/note.md', "# Hello\n");
        (new Process(['git', '-C', $seedPath, 'config', 'user.email', 'test@example.com']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'config', 'user.name', 'Test']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'add', '.']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'commit', '-m', 'initial']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'branch', '-M', 'main']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'push', 'origin', 'main']))->mustRun();

        // The container's git has no init.defaultBranch configured, so the bare
        // repo's HEAD still points at refs/heads/master even though only "main"
        // was ever pushed. Without repointing HEAD, `git clone` checks out the
        // unborn master branch (no files) instead of main.
        (new Process(['git', '-C', $this->bareRepoPath, 'symbolic-ref', 'HEAD', 'refs/heads/main']))->mustRun();

        $this->service = new GitSyncService(new VaultGitCheckout($this->checkoutPath, $this->bareRepoPath));
    }

    public function testClonesRepoWhenCheckoutDoesNotExist(): void
    {
        $this->service->sync();

        self::assertFileExists($this->checkoutPath . '/note.md');
    }

    public function testPullsLatestChangesWhenCheckoutAlreadyExists(): void
    {
        $this->service->sync();

        // Push a new commit to the bare repo from a second clone.
        $secondClone = sys_get_temp_dir() . '/rpgnotes-second-' . uniqid();
        (new Process(['git', 'clone', $this->bareRepoPath, $secondClone]))->mustRun();
        file_put_contents($secondClone . '/note2.md', "# New note\n");
        (new Process(['git', '-C', $secondClone, 'config', 'user.email', 'test@example.com']))->mustRun();
        (new Process(['git', '-C', $secondClone, 'config', 'user.name', 'Test']))->mustRun();
        (new Process(['git', '-C', $secondClone, 'add', '.']))->mustRun();
        (new Process(['git', '-C', $secondClone, 'commit', '-m', 'second']))->mustRun();
        (new Process(['git', '-C', $secondClone, 'push', 'origin', 'main']))->mustRun();

        $this->service->sync();

        self::assertFileExists($this->checkoutPath . '/note2.md');
    }

    public function testPullsLatestChangesWhenDefaultBranchIsNotMain(): void
    {
        // Regression: GitSyncService must follow the remote's actual default
        // branch (via origin/HEAD) instead of assuming it's called "main" —
        // plenty of real repos still default to "master".
        $bareRepoPath = sys_get_temp_dir() . '/rpgnotes-bare-master-' . uniqid();
        $checkoutPath = sys_get_temp_dir() . '/rpgnotes-checkout-master-' . uniqid();

        (new Process(['git', 'init', '--bare', $bareRepoPath]))->mustRun();

        $seedPath = sys_get_temp_dir() . '/rpgnotes-seed-master-' . uniqid();
        (new Process(['git', 'clone', $bareRepoPath, $seedPath]))->mustRun();
        file_put_contents($seedPath . '/note.md', "# Hello\n");
        (new Process(['git', '-C', $seedPath, 'config', 'user.email', 'test@example.com']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'config', 'user.name', 'Test']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'add', '.']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'commit', '-m', 'initial']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'branch', '-M', 'master']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'push', 'origin', 'master']))->mustRun();
        (new Process(['git', '-C', $bareRepoPath, 'symbolic-ref', 'HEAD', 'refs/heads/master']))->mustRun();

        $service = new GitSyncService(new VaultGitCheckout($checkoutPath, $bareRepoPath));
        $service->sync();
        self::assertFileExists($checkoutPath . '/note.md');

        file_put_contents($seedPath . '/note2.md', "# New note\n");
        (new Process(['git', '-C', $seedPath, 'add', '.']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'commit', '-m', 'second']))->mustRun();
        (new Process(['git', '-C', $seedPath, 'push', 'origin', 'master']))->mustRun();

        $service->sync();

        self::assertFileExists($checkoutPath . '/note2.md');
    }

    public function testAbortsSyncWhenLocalHasUnpushedCommits(): void
    {
        $this->service->sync();

        $checkout = new VaultGitCheckout($this->checkoutPath, $this->bareRepoPath);
        file_put_contents($this->checkoutPath . '/local-only.md', "# Local only\n");
        $checkout->run(['git', '-C', $this->checkoutPath, 'config', 'user.email', 'test@example.com']);
        $checkout->run(['git', '-C', $this->checkoutPath, 'config', 'user.name', 'Test']);
        $checkout->run(['git', '-C', $this->checkoutPath, 'add', 'local-only.md']);
        $checkout->run(['git', '-C', $this->checkoutPath, 'commit', '-m', 'unpushed']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unpushed commits');

        $this->service->sync();
    }

    public function testThrowsOnInvalidRepoUrl(): void
    {
        $service = new GitSyncService(new VaultGitCheckout($this->checkoutPath, '/nonexistent/path/to/repo'));

        $this->expectException(\RuntimeException::class);
        $service->sync();
    }

    public function testExceptionMessageDoesNotLeakCredentialsFromRepoUrl(): void
    {
        $service = new GitSyncService(new VaultGitCheckout(
            $this->checkoutPath,
            'https://user:secret123@nonexistent.invalid/repo.git'
        ));

        try {
            $service->sync();
            self::fail('Expected a RuntimeException to be thrown.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('secret123', $e->getMessage());
            self::assertStringContainsString('https://***@nonexistent.invalid/repo.git', $e->getMessage());
        }
    }
}
