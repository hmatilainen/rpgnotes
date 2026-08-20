<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\OAuth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MetadataControllerTest extends WebTestCase
{
    public function testProtectedResourceMetadata(): void
    {
        $client = static::createClient();
        $client->request('GET', '/.well-known/oauth-protected-resource');
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('https://rpg.kuura.art/mcp', $data['resource']);
        self::assertContains('https://rpg.kuura.art', $data['authorization_servers']);
    }

    public function testAuthorizationServerMetadata(): void
    {
        $client = static::createClient();
        $client->request('GET', '/.well-known/oauth-authorization-server');
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('https://rpg.kuura.art', $data['issuer']);
        self::assertSame(['S256'], $data['code_challenge_methods_supported']);
        self::assertSame('https://rpg.kuura.art/authorize', $data['authorization_endpoint']);
        self::assertSame('https://rpg.kuura.art/oauth/token', $data['token_endpoint']);
        self::assertSame('https://rpg.kuura.art/oauth/register', $data['registration_endpoint']);
    }
}
