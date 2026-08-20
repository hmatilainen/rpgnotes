<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\OAuth;

use App\Entity\User;
use App\Service\OAuth\OAuthServerConfig;
use App\Service\OAuth\UserOAuthClientService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OAuthFlowTest extends WebTestCase
{
    private const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

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

    public function testAuthorizationCodeFlowIssuesMcpAccessToken(): void
    {
        $client = static::createClient();
        $player = $this->createPlayer('oauth-flow-player');
        $oauthClients = static::getContainer()->get(UserOAuthClientService::class);
        $credentials = $oauthClients->regenerateSecretForUser($player);
        $config = static::getContainer()->get(OAuthServerConfig::class);

        [$verifier, $challenge] = $this->pkcePair();

        $client->loginUser($player);
        $client->request('GET', '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $credentials['clientId'],
            'redirect_uri' => self::CLAUDE_CALLBACK,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => 'test-state',
            'resource' => $config->getResource(),
        ]));
        self::assertResponseIsSuccessful();
        $client->submitForm('Allow');

        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertArrayHasKey('code', $query);
        self::assertSame('test-state', $query['state'] ?? null);

        $client->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'redirect_uri' => self::CLAUDE_CALLBACK,
            'client_id' => $credentials['clientId'],
            'client_secret' => $credentials['clientSecret'],
            'code_verifier' => $verifier,
            'resource' => $config->getResource(),
        ]);
        self::assertResponseIsSuccessful();
        $tokenPayload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Bearer', $tokenPayload['token_type']);
        self::assertNotEmpty($tokenPayload['access_token']);

        $client->request(
            'POST',
            '/mcp',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenPayload['access_token']],
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
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    private function cleanUp(): void
    {
        if (!static::$booted) {
            return;
        }

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\Entity\OAuthRefreshToken')->execute();
        $em->createQuery('DELETE FROM App\Entity\OAuthAccessToken')->execute();
        $em->createQuery('DELETE FROM App\Entity\OAuthAuthorizationCode')->execute();
        $em->createQuery('DELETE FROM App\Entity\UserOAuthClient')->execute();
        $em->createQuery('DELETE FROM App\Entity\OAuthDynamicClient')->execute();
        $em->createQuery('DELETE FROM App\Entity\UserApiToken')->execute();
        $em->createQuery('DELETE FROM App\Entity\User')->execute();
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
