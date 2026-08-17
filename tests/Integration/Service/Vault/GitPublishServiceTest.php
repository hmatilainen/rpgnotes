<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Vault;

use App\Service\Vault\GitPublishService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitPublishServiceTest extends TestCase
{
    private string $bareRepoPath;
    private string $checkoutPath;

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
    }

    public function testAddsFileAndPushesToRemote(): void
    {
        $service = new GitPublishService($this->checkoutPath, $this->bareRepoPath);
        $relativePath = 'Reports/1-10/Report-2 16.8.1367 New session.md';
        $content = "---\npublished_at: 2026-08-16T20:00:00+01:00\n---\n\n## New session\n\nBody\n";

        $service->addAndPush($relativePath, $content, 'Add session report #2');

        self::assertFileExists($this->checkoutPath . '/' . $relativePath);

        $verifyClone = sys_get_temp_dir() . '/rpgnotes-publish-verify-' . uniqid();
        (new Process(['git', 'clone', $this->bareRepoPath, $verifyClone]))->mustRun();
        self::assertFileExists($verifyClone . '/' . $relativePath);
    }
}
