<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateAdminCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testCreatesAdminWithHashedPassword(): void
    {
        $application = new Application(self::$kernel);
        $command = $application->find('app:create-admin');
        $commandTester = new CommandTester($command);
        $commandTester->setInputs(['admin', 'super-secret-password']);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();

        $users = static::getContainer()->get(UserRepository::class);
        $user = $users->findOneByUsername('admin');

        self::assertNotNull($user);
        self::assertSame('ROLE_ADMIN', $user->getRole());
        self::assertNotSame('super-secret-password', $user->getPasswordHash());

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, 'super-secret-password'));
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM App\Entity\User')->execute();
        parent::tearDown();
    }
}
