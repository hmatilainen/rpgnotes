<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Service\Api\UserApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AiAccessControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        if (!static::$booted) {
            return;
        }

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\Entity\UserApiToken')->execute();
        $em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testPlayerCanGenerateToken(): void
    {
        $client = static::createClient();
        $player = $this->createPlayer('ai-player');

        $client->loginUser($player);
        $client->request('GET', '/ai-access');
        self::assertResponseIsSuccessful();

        $client->submitForm('Generate API token');

        self::assertResponseRedirects('/ai-access');
        $client->followRedirect();
        self::assertSelectorTextContains('.token-reveal', 'New API token');
    }

    public function testMcpInitializeWithValidToken(): void
    {
        $client = static::createClient();
        $player = $this->createPlayer('mcp-player');
        $rawToken = static::getContainer()->get(UserApiTokenService::class)->generateForUser($player);

        $client->request(
            'POST',
            '/mcp',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$rawToken],
            content: json_encode([
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'id' => 1,
                'params' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => 'test', 'version' => '1'],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2.0', $data['jsonrpc']);
        self::assertArrayHasKey('result', $data);
    }

    public function testMcpRejectsMissingToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/mcp', content: '{"jsonrpc":"2.0","method":"initialize","id":1,"params":{}}');

        self::assertResponseStatusCodeSame(401);
    }

    private function createPlayer(string $username): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setLabel('Player');
        $user->setUsername($username);
        $user->setRole('ROLE_PLAYER');
        $user->setPasswordHash($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
