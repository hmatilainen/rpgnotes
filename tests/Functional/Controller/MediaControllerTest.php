<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\HiddenPath;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MediaControllerTest extends WebTestCase
{
    // 1x1 transparent PNG
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = $_SERVER['VAULT_PATH'] ?? $_ENV['VAULT_PATH'] ?? '/tmp/rpgnotes-test-vault';
        @mkdir($this->vault . '/Reports', 0777, true);
        @mkdir($this->vault . '/Secret', 0777, true);
        file_put_contents($this->vault . '/poster 1.png', base64_decode(self::PNG));
        file_put_contents($this->vault . '/Secret/hidden.png', base64_decode(self::PNG));
        file_put_contents($this->vault . '/Reports/notes.txt', 'not an image');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM '.HiddenPath::class)->execute();
        $hidden = new HiddenPath();
        $hidden->setPath('Secret');
        $em->persist($hidden);
        $em->flush();
        self::ensureKernelShutdown();
    }

    public function testServesImageByFilename(): void
    {
        $client = static::createClient();
        $client->request('GET', '/media/poster%201.png');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('image/png', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testRefusesImagesInHiddenFolders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/media/hidden.png');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRefusesNonImageFiles(): void
    {
        $client = static::createClient();
        $client->request('GET', '/media/notes.txt');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRefusesPathTraversal(): void
    {
        $client = static::createClient();
        $client->request('GET', '/media/..%2F..%2Fetc%2Fpasswd.png');

        self::assertResponseStatusCodeSame(404);
    }
}
