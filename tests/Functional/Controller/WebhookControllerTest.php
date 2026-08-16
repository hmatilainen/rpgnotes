<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Service\Vault\GitSyncService;
use App\Service\Vault\IndexResult;
use App\Service\Vault\NoteIndexer;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WebhookControllerTest extends WebTestCase
{
    public function testRejectsRequestWithoutToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/webhook/sync');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testRejectsRequestWithWrongToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/webhook/sync', server: [
            'HTTP_AUTHORIZATION' => 'Bearer wrong-secret',
        ]);

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testTriggersSyncWithCorrectToken(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $gitSync = $this->createMock(GitSyncService::class);
        $gitSync->expects(self::once())->method('sync');
        $container->set(GitSyncService::class, $gitSync);

        $indexer = $this->createMock(NoteIndexer::class);
        $indexer->expects(self::once())->method('index')->willReturn(new IndexResult(3, 0));
        $container->set(NoteIndexer::class, $indexer);

        $client->request('POST', '/webhook/sync', server: [
            'HTTP_AUTHORIZATION' => 'Bearer test-secret',
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"updated":3,"deleted":0}',
            $client->getResponse()->getContent()
        );
    }

    public function testReturns500AndDoesNotLeakDetailsOnSyncFailure(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $gitSync = $this->createMock(GitSyncService::class);
        $gitSync->method('sync')->willThrowException(new \RuntimeException('git exploded'));
        $container->set(GitSyncService::class, $gitSync);

        $client->request('POST', '/webhook/sync', server: [
            'HTTP_AUTHORIZATION' => 'Bearer test-secret',
        ]);

        self::assertSame(500, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('git exploded', (string) $client->getResponse()->getContent());
    }
}
