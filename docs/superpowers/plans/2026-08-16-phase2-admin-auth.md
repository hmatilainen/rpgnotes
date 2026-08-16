# Phase 2: Admin & Invite-Only Access Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an admin account, invite-only player registration (with invite links doubling as password reset), and admin-only visibility for hidden content — replacing the static hidden-folder config with an admin-editable list supporting individual files as well as folders, at any depth.

**Architecture:** Symfony Security component (session form login) backed by a single `User` entity covering both roles. A new `HiddenPath` entity replaces the static `app.vault.hidden_dirs` config; hidden content is now indexed (not skipped) with a `Note.hidden` flag, and every listing/lookup path becomes role-aware — non-admins get identical behavior to today (hidden content 404s, excluded from all listings), admins see everything.

**Tech Stack:** Symfony 7.4 (`symfony/security-bundle`, `symfony/security-csrf`), Doctrine ORM, Twig, PostgreSQL. Docker stack already running (`docker compose up -d` from repo root); PHPUnit via `docker compose exec app bin/phpunit`.

Spec: [docs/superpowers/specs/2026-08-16-phase2-admin-auth-design.md](../specs/2026-08-16-phase2-admin-auth-design.md)

## Global Constraints

- One `User` entity for both admin and players (`role`: `ROLE_ADMIN` | `ROLE_PLAYER`), not separate tables.
- No email anywhere — login is username + password only.
- Invite tokens are single-use, expire after exactly 2 weeks from generation, and generating a new one for a player invalidates any previous unused token (only one valid token per player at a time).
- Registration form (`/register/{token}`) is identical whether it's a player's first registration or a password reset via a regenerated invite — both fields always blank, both always set on submit.
- Invalid/expired/already-consumed/nonexistent invite tokens all show the identical "no longer valid" message — no distinguishing which case it was.
- Wikilinks into hidden content render as plain text for **every** viewer, including admins — never a real link. This does not change in this phase.
- Hidden-content matching is by exact path-segment equality against ancestor directories (or the file's own path), never string-prefix matching — `Locations2/...` must never be hidden by a `HiddenPath` entry of `Locations`.
- A hidden note requested by a non-admin (including a guessed/direct URL) returns a plain 404, indistinguishable from a genuinely nonexistent note.
- `/admin/*` routes require `ROLE_ADMIN` via Symfony access control, enforced at the firewall level, not ad-hoc per-controller checks.
- Admin-facing POST forms (add player, generate invite, add/remove hidden path) are CSRF-protected using Symfony's built-in CSRF token manager, consistent with the login form's own built-in protection.
- `.obsidian` and `docs` remain hardcoded, non-toggleable scanner exclusions — they are not real vault content and were never part of the hidden-folder concept.

---

## File Structure

```
src/Entity/User.php                                    (new)
src/Entity/HiddenPath.php                               (new)
src/Entity/Note.php                                     (modified — add `hidden` field)
src/Repository/UserRepository.php                       (new)
src/Repository/HiddenPathRepository.php                 (new)
src/Repository/NoteRepository.php                       (modified — role-aware queries)
src/Service/Vault/HiddenPathMatcher.php                 (new)
src/Service/Vault/VaultFileScanner.php                  (modified — drop hidden-dir filtering)
src/Service/Vault/NoteIndexer.php                       (modified — index hidden notes, flag them)
src/Service/Sidebar/SidebarBuilder.php                   (modified — role-aware)
src/Service/Markdown/NoteDraft.php                       (modified — add `hidden` field)
src/Command/CreateAdminCommand.php                       (new)
src/Controller/SecurityController.php                    (new)
src/Controller/RegistrationController.php                (new)
src/Controller/Admin/PlayerController.php                (new)
src/Controller/Admin/HiddenPathController.php             (new)
src/Controller/Admin/DashboardController.php              (new)
src/Controller/NoteController.php                        (modified — hidden-note 404)
src/Controller/FrontPageController.php                   (modified — role-aware)
config/packages/security.yaml                             (new)
config/services.yaml                                      (modified — remove hidden_dirs param)
templates/security/login.html.twig                        (new)
templates/registration/register.html.twig                 (new)
templates/registration/invalid_invite.html.twig            (new)
templates/admin/dashboard.html.twig                        (new)
templates/admin/players.html.twig                          (new)
templates/admin/hidden_paths.html.twig                     (new)
templates/base.html.twig                                  (modified — navbar auth links)
migrations/...                                             (new — users, hidden_paths tables; notes.hidden column)
tests/Unit/Service/Vault/HiddenPathMatcherTest.php          (new)
tests/Unit/Service/Vault/VaultFileScannerTest.php           (modified)
tests/Integration/Service/Vault/NoteIndexerTest.php          (modified)
tests/Integration/Service/Sidebar/SidebarBuilderTest.php     (modified)
tests/Functional/Command/CreateAdminCommandTest.php          (new)
tests/Functional/Controller/SecurityControllerTest.php       (new)
tests/Functional/Controller/RegistrationControllerTest.php   (new)
tests/Functional/Controller/Admin/PlayerControllerTest.php   (new)
tests/Functional/Controller/Admin/HiddenPathControllerTest.php (new)
tests/Functional/Controller/NoteControllerTest.php            (modified)
tests/Functional/Controller/FrontPageControllerTest.php       (modified)
```

---

### Task 1: Auth foundation — User entity, Security bundle, login/logout, admin bootstrap

**Files:**
- Create: `src/Entity/User.php`, `src/Repository/UserRepository.php`
- Create: `config/packages/security.yaml`
- Create: `src/Controller/SecurityController.php`, `templates/security/login.html.twig`
- Create: `src/Command/CreateAdminCommand.php`
- Modify: `templates/base.html.twig` (login/logout navbar links only — no Admin link yet, that route doesn't exist until Task 7)
- New migration (generated)
- Test: `tests/Functional/Command/CreateAdminCommandTest.php`, `tests/Functional/Controller/SecurityControllerTest.php`

**Interfaces:**
- Produces: `User` entity (`getUsername()`, `getRole()`, `isInviteValid()`, implements `UserInterface`/`PasswordAuthenticatedUserInterface`), `UserRepository::findOneByUsername()`, `findOneByInviteToken()`, `findAllPlayers()` — all consumed by every later task in this plan.
- Produces: working `/login`, `/logout` routes and `bin/console app:create-admin`.

- [ ] **Step 1: Require the security packages**

```bash
docker compose exec app composer require symfony/security-bundle symfony/security-csrf
```

- [ ] **Step 2: Write the User entity**

`src/Entity/User.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $label = '';

    #[ORM\Column(length: 180, unique: true, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(length: 20)]
    private string $role = 'ROLE_PLAYER';

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $inviteToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $inviteTokenExpiresAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): void
    {
        $this->username = $username;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(?string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }

    public function getInviteToken(): ?string
    {
        return $this->inviteToken;
    }

    public function setInviteToken(?string $inviteToken): void
    {
        $this->inviteToken = $inviteToken;
    }

    public function getInviteTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->inviteTokenExpiresAt;
    }

    public function setInviteTokenExpiresAt(?\DateTimeImmutable $inviteTokenExpiresAt): void
    {
        $this->inviteTokenExpiresAt = $inviteTokenExpiresAt;
    }

    public function isInviteValid(): bool
    {
        return $this->inviteToken !== null
            && $this->inviteTokenExpiresAt !== null
            && $this->inviteTokenExpiresAt > new \DateTimeImmutable();
    }

    public function getRoles(): array
    {
        return [$this->role];
    }

    public function eraseCredentials(): void
    {
        // No plaintext/temporary sensitive data stored on this entity.
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }
}
```

- [ ] **Step 3: Write UserRepository**

`src/Repository/UserRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => $username]);
    }

    public function findOneByInviteToken(string $token): ?User
    {
        return $this->findOneBy(['inviteToken' => $token]);
    }

    /**
     * @return User[]
     */
    public function findAllPlayers(): array
    {
        return $this->findBy(['role' => 'ROLE_PLAYER'], ['label' => 'ASC']);
    }
}
```

- [ ] **Step 4: Generate and run the migration**

```bash
docker compose exec app bin/console make:migration --no-interaction
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app bin/console doctrine:migrations:migrate --env=test --no-interaction
```
Expected: a new migration creating the `users` table with columns matching the entity above (`id`, `label`, `username` unique nullable, `password_hash` nullable, `role`, `invite_token` unique nullable, `invite_token_expires_at` nullable).

- [ ] **Step 5: Write the Security configuration**

`config/packages/security.yaml`:
```yaml
security:
    password_hashers:
        App\Entity\User:
            algorithm: auto

    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: username

    firewalls:
        main:
            lazy: true
            provider: app_user_provider
            form_login:
                login_path: login
                check_path: login
                default_target_path: front_page
            logout:
                path: logout
                target: front_page

    access_control:
        - { path: ^/admin, roles: ROLE_ADMIN }
```

- [ ] **Step 6: Write SecurityController and the login template**

`src/Controller/SecurityController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/logout', name: 'logout')]
    public function logout(): never
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
```

`templates/security/login.html.twig`:
```twig
{% extends 'base.html.twig' %}

{% block title %}Log in — RPG Notes{% endblock %}

{% block body %}
    <h1>Log in</h1>
    {% if error %}
        <p class="form-error">Invalid username or password.</p>
    {% endif %}
    <form method="post">
        <label for="username">Username</label>
        <input type="text" id="username" name="_username" value="{{ last_username }}" required>

        <label for="password">Password</label>
        <input type="password" id="password" name="_password" required>

        <input type="hidden" name="_csrf_token" value="{{ csrf_token('authenticate') }}">

        <button type="submit">Log in</button>
    </form>
{% endblock %}
```

- [ ] **Step 7: Add navbar login/logout links**

Modify `templates/base.html.twig` from:
```twig
    <header class="navbar">
        <a href="/">RPG Notes — Forgotten Realms</a>
    </header>
```
to:
```twig
    <header class="navbar">
        <a href="/">RPG Notes — Forgotten Realms</a>
        <nav class="navbar-auth">
            {% if app.user %}
                <span>Logged in as {{ app.user.userIdentifier }}</span>
                <a href="{{ path('logout') }}">Log out</a>
            {% else %}
                <a href="{{ path('login') }}">Log in</a>
            {% endif %}
        </nav>
    </header>
```

- [ ] **Step 8: Write CreateAdminCommand**

`src/Command/CreateAdminCommand.php`:
```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-admin', description: 'Create the admin account')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $io->ask('Admin username');

        $passwordQuestion = new Question('Admin password');
        $passwordQuestion->setHidden(true);
        $passwordQuestion->setHiddenFallback(false);
        $password = $io->askQuestion($passwordQuestion);

        if ($username === null || $password === null || trim((string) $username) === '' || trim((string) $password) === '') {
            $io->error('Username and password are required.');

            return Command::FAILURE;
        }

        $user = new User();
        $user->setLabel($username);
        $user->setUsername($username);
        $user->setRole('ROLE_ADMIN');
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Admin account "%s" created.', $username));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 9: Write the failing tests**

`tests/Functional/Command/CreateAdminCommandTest.php`:
```php
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
```

`tests/Functional/Controller/SecurityControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SecurityControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUpUsers();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $this->cleanUpUsers();
        parent::tearDown();
    }

    private function cleanUpUsers(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testLoginSucceedsWithCorrectCredentials(): void
    {
        $client = static::createClient();
        $this->createUser($client, 'alice', 'correct-password', 'ROLE_ADMIN');

        $client->request('GET', '/login');
        $client->submitForm('Log in', [
            '_username' => 'alice',
            '_password' => 'correct-password',
        ]);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Logged in as alice');
    }

    public function testLoginFailsWithWrongPassword(): void
    {
        $client = static::createClient();
        $this->createUser($client, 'bob', 'correct-password', 'ROLE_PLAYER');

        $client->request('GET', '/login');
        $client->submitForm('Log in', [
            '_username' => 'bob',
            '_password' => 'wrong-password',
        ]);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Invalid username or password.');
    }

    public function testLogoutEndsSession(): void
    {
        $client = static::createClient();
        $this->createUser($client, 'carol', 'correct-password', 'ROLE_PLAYER');

        $client->request('GET', '/login');
        $client->submitForm('Log in', [
            '_username' => 'carol',
            '_password' => 'correct-password',
        ]);
        $client->followRedirect();

        $client->request('GET', '/logout');
        $client->followRedirect();

        self::assertSelectorExists('a[href="/login"]');
    }

    private function createUser(KernelBrowser $client, string $username, string $password, string $role): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setLabel($username);
        $user->setUsername($username);
        $user->setRole($role);
        $user->setPasswordHash($hasher->hashPassword($user, $password));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `docker compose exec app bin/phpunit tests/Functional/Command/CreateAdminCommandTest.php tests/Functional/Controller/SecurityControllerTest.php`
Expected: PASS (4 tests).

- [ ] **Step 11: Run the full suite**

Run: `docker compose exec app bin/phpunit`
Expected: all tests pass, no regressions.

- [ ] **Step 12: Commit**

```bash
git add composer.json composer.lock src/Entity/User.php src/Repository/UserRepository.php migrations/ config/packages/security.yaml src/Controller/SecurityController.php templates/security/login.html.twig templates/base.html.twig src/Command/CreateAdminCommand.php tests/Functional/Command/CreateAdminCommandTest.php tests/Functional/Controller/SecurityControllerTest.php
git commit -m "Add auth foundation: User entity, Security bundle, login/logout, admin bootstrap"
```

---

### Task 2: Invite-based registration

**Files:**
- Create: `src/Controller/RegistrationController.php`
- Create: `templates/registration/register.html.twig`, `templates/registration/invalid_invite.html.twig`
- Test: `tests/Functional/Controller/RegistrationControllerTest.php`

**Interfaces:**
- Consumes: `User::isInviteValid()`, `UserRepository::findOneByInviteToken()`, `findOneByUsername()` (Task 1).
- Produces: `GET/POST /register/{token}` route (`register` route name), consumed by Task 3's invite-link generation.

- [ ] **Step 1: Write the failing tests**

`tests/Functional/Controller/RegistrationControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUpUsers();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $this->cleanUpUsers();
        parent::tearDown();
    }

    private function cleanUpUsers(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testValidTokenRegistersAndAllowsLogin(): void
    {
        $client = static::createClient();
        $this->makePendingPlayer('Mikko', 'valid-token-123', new \DateTimeImmutable('+1 day'));

        $client->request('GET', '/register/valid-token-123');
        self::assertResponseIsSuccessful();

        $client->submitForm('Create account', [
            'username' => 'mikko99',
            'password' => 'a-strong-password',
        ]);
        self::assertResponseRedirects('/login');

        $client->request('GET', '/login');
        $client->submitForm('Log in', [
            '_username' => 'mikko99',
            '_password' => 'a-strong-password',
        ]);
        self::assertResponseRedirects('/');
    }

    public function testTokenIsConsumedAfterUse(): void
    {
        $client = static::createClient();
        $this->makePendingPlayer('Mikko', 'one-time-token', new \DateTimeImmutable('+1 day'));

        $client->request('GET', '/register/one-time-token');
        $client->submitForm('Create account', [
            'username' => 'mikko99',
            'password' => 'a-strong-password',
        ]);

        $client->request('GET', '/register/one-time-token');
        self::assertResponseStatusCodeSame(404);
    }

    public function testExpiredTokenShowsInvalidPage(): void
    {
        $client = static::createClient();
        $this->makePendingPlayer('Mikko', 'expired-token', new \DateTimeImmutable('-1 day'));

        $client->request('GET', '/register/expired-token');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'no longer valid');
    }

    public function testNonexistentTokenShowsInvalidPage(): void
    {
        $client = static::createClient();

        $client->request('GET', '/register/never-issued');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'no longer valid');
    }

    public function testDuplicateUsernameShowsFormError(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $existing = new User();
        $existing->setLabel('Existing');
        $existing->setUsername('taken');
        $existing->setRole('ROLE_PLAYER');
        $existing->setPasswordHash($hasher->hashPassword($existing, 'whatever'));
        $em->persist($existing);

        $this->makePendingPlayer('Mikko', 'dup-token', new \DateTimeImmutable('+1 day'));

        $client->request('GET', '/register/dup-token');
        $client->submitForm('Create account', [
            'username' => 'taken',
            'password' => 'a-strong-password',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.form-error', 'already taken');
    }

    private function makePendingPlayer(string $label, string $token, \DateTimeImmutable $expiresAt): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setLabel($label);
        $user->setRole('ROLE_PLAYER');
        $user->setInviteToken($token);
        $user->setInviteTokenExpiresAt($expiresAt);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/RegistrationControllerTest.php`
Expected: FAIL — no `/register/{token}` route exists yet.

- [ ] **Step 3: Write RegistrationController**

`src/Controller/RegistrationController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/register/{token}', name: 'register', methods: ['GET', 'POST'])]
    public function __invoke(string $token, Request $request): Response
    {
        $user = $this->users->findOneByInviteToken($token);

        if ($user === null || !$user->isInviteValid()) {
            return $this->render('registration/invalid_invite.html.twig', [], new Response('', 404));
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $username = trim((string) $request->request->get('username'));
            $password = (string) $request->request->get('password');
            $existing = $username !== '' ? $this->users->findOneByUsername($username) : null;

            if ($username === '' || $password === '') {
                $error = 'Username and password are required.';
            } elseif ($existing !== null && $existing !== $user) {
                $error = 'That username is already taken.';
            } else {
                $user->setUsername($username);
                $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
                $user->setInviteToken(null);
                $user->setInviteTokenExpiresAt(null);
                $this->em->flush();

                return $this->redirectToRoute('login');
            }
        }

        return $this->render('registration/register.html.twig', [
            'token' => $token,
            'error' => $error,
        ]);
    }
}
```

- [ ] **Step 4: Write the templates**

`templates/registration/register.html.twig`:
```twig
{% extends 'base.html.twig' %}

{% block title %}Create your account — RPG Notes{% endblock %}

{% block body %}
    <h1>Create your account</h1>
    {% if error %}
        <p class="form-error">{{ error }}</p>
    {% endif %}
    <form method="post">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>

        <button type="submit">Create account</button>
    </form>
{% endblock %}
```

`templates/registration/invalid_invite.html.twig`:
```twig
{% extends 'base.html.twig' %}

{% block title %}Invite link not valid — RPG Notes{% endblock %}

{% block body %}
    <h1>This invite link is no longer valid</h1>
    <p>Ask whoever invited you to send a new link.</p>
{% endblock %}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/RegistrationControllerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Run the full suite**

Run: `docker compose exec app bin/phpunit`
Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/RegistrationController.php templates/registration tests/Functional/Controller/RegistrationControllerTest.php
git commit -m "Add invite-based registration (also serves as password reset)"
```

---

### Task 3: Admin players UI

**Files:**
- Create: `src/Controller/Admin/PlayerController.php`
- Create: `templates/admin/players.html.twig`
- Test: `tests/Functional/Controller/Admin/PlayerControllerTest.php`

**Interfaces:**
- Consumes: `UserRepository::findAllPlayers()` (Task 1), `register` route (Task 2, linked from the template).

- [ ] **Step 1: Write the failing tests**

`tests/Functional/Controller/Admin/PlayerControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PlayerControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUpUsers();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $this->cleanUpUsers();
        parent::tearDown();
    }

    private function cleanUpUsers(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/players');

        self::assertResponseRedirects('/login');
    }

    public function testNonAdminGetsForbidden(): void
    {
        $client = static::createClient();
        $player = $this->createUser('player1', 'ROLE_PLAYER');
        $client->loginUser($player);

        $client->request('GET', '/admin/players');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanViewAndAddPlayer(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin1', 'ROLE_ADMIN');
        $client->loginUser($admin);

        $client->request('GET', '/admin/players');
        self::assertResponseIsSuccessful();

        $crawler = $client->getCrawler();
        $form = $crawler->filter('form[action$="/admin/players/add"]')->form();
        $client->submit($form, ['label' => 'Mikko']);

        self::assertResponseRedirects('/admin/players');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Mikko');
    }

    public function testAdminCanGenerateAndRegenerateInvite(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin2', 'ROLE_ADMIN');
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $player = new User();
        $player->setLabel('Mikko');
        $player->setRole('ROLE_PLAYER');
        $em->persist($player);
        $em->flush();

        $client->request('GET', '/admin/players');
        $crawler = $client->getCrawler();
        $form = $crawler->filter('form[action$="/' . $player->getId() . '/invite"]')->form();
        $client->submit($form);

        self::assertResponseRedirects('/admin/players');
        $em->refresh($player);
        $firstToken = $player->getInviteToken();
        self::assertNotNull($firstToken);
        self::assertTrue($player->isInviteValid());

        $client->request('GET', '/admin/players');
        $crawler = $client->getCrawler();
        $form = $crawler->filter('form[action$="/' . $player->getId() . '/invite"]')->form();
        $client->submit($form);

        $em->refresh($player);
        self::assertNotSame($firstToken, $player->getInviteToken());
    }

    private function createUser(string $username, string $role): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setLabel($username);
        $user->setUsername($username);
        $user->setRole($role);
        $user->setPasswordHash($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/Admin/PlayerControllerTest.php`
Expected: FAIL — `/admin/players` doesn't exist yet.

- [ ] **Step 3: Write PlayerController**

`src/Controller/Admin/PlayerController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/players')]
final class PlayerController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_players', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/players.html.twig', [
            'players' => $this->users->findAllPlayers(),
        ]);
    }

    #[Route('/add', name: 'admin_players_add', methods: ['POST'])]
    public function add(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_players_add', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $label = trim((string) $request->request->get('label'));

        if ($label !== '') {
            $player = new User();
            $player->setLabel($label);
            $player->setRole('ROLE_PLAYER');
            $this->em->persist($player);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_players');
    }

    #[Route('/{id}/invite', name: 'admin_players_invite', methods: ['POST'])]
    public function generateInvite(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_players_invite', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->users->find($id);

        if ($player === null || $player->getRole() !== 'ROLE_PLAYER') {
            throw $this->createNotFoundException();
        }

        $player->setInviteToken(bin2hex(random_bytes(32)));
        $player->setInviteTokenExpiresAt(new \DateTimeImmutable('+2 weeks'));
        $this->em->flush();

        return $this->redirectToRoute('admin_players');
    }
}
```

- [ ] **Step 4: Write the template**

`templates/admin/players.html.twig`:
```twig
{% extends 'base.html.twig' %}

{% block title %}Players — Admin — RPG Notes{% endblock %}

{% block body %}
    <h1>Players</h1>

    <form method="post" action="{{ path('admin_players_add') }}">
        <label for="label">Player name</label>
        <input type="text" id="label" name="label" required>
        <input type="hidden" name="_token" value="{{ csrf_token('admin_players_add') }}">
        <button type="submit">Add player</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Invite</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            {% for player in players %}
                <tr>
                    <td>{{ player.label }}</td>
                    <td>{{ player.username ?: '—' }}</td>
                    <td>
                        {% if player.inviteToken and player.isInviteValid %}
                            <a href="{{ url('register', {token: player.inviteToken}) }}">{{ url('register', {token: player.inviteToken}) }}</a>
                        {% else %}
                            no active invite
                        {% endif %}
                    </td>
                    <td>
                        <form method="post" action="{{ path('admin_players_invite', {id: player.id}) }}">
                            <input type="hidden" name="_token" value="{{ csrf_token('admin_players_invite') }}">
                            <button type="submit">{{ player.username ? 'Regenerate invite' : 'Generate invite link' }}</button>
                        </form>
                    </td>
                </tr>
            {% endfor %}
        </tbody>
    </table>
{% endblock %}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/Admin/PlayerControllerTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the full suite**

Run: `docker compose exec app bin/phpunit`
Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/Admin/PlayerController.php templates/admin/players.html.twig tests/Functional/Controller/Admin/PlayerControllerTest.php
git commit -m "Add admin UI for managing players and invite links"
```

---

### Task 4: HiddenPath entity + admin UI

**Files:**
- Create: `src/Entity/HiddenPath.php`, `src/Repository/HiddenPathRepository.php`
- Create: `src/Controller/Admin/HiddenPathController.php`, `templates/admin/hidden_paths.html.twig`
- New migration (generated, then manually edited to seed the two current static paths)
- Test: `tests/Functional/Controller/Admin/HiddenPathControllerTest.php`

**Interfaces:**
- Produces: `HiddenPath` entity, `HiddenPathRepository::findAllPaths(): string[]` — consumed by Task 5's `NoteIndexer` changes.

- [ ] **Step 1: Write the HiddenPath entity**

`src/Entity/HiddenPath.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HiddenPathRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HiddenPathRepository::class)]
#[ORM\Table(name: 'hidden_paths')]
class HiddenPath
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 1024, unique: true)]
    private string $path = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
    }
}
```

- [ ] **Step 2: Write HiddenPathRepository**

`src/Repository/HiddenPathRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HiddenPath;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HiddenPath>
 */
class HiddenPathRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HiddenPath::class);
    }

    /**
     * @return string[]
     */
    public function findAllPaths(): array
    {
        return array_map(
            static fn (HiddenPath $hiddenPath) => $hiddenPath->getPath(),
            $this->findBy([], ['path' => 'ASC'])
        );
    }
}
```

- [ ] **Step 3: Generate the migration, then add the seed data manually**

```bash
docker compose exec app bin/console make:migration --no-interaction
```
Open the generated migration file and add these two lines to the end of its `up()` method, after the `CREATE TABLE hidden_paths ...` statement Doctrine generated:
```php
        $this->addSql("INSERT INTO hidden_paths (path) VALUES ('A - GM')");
        $this->addSql("INSERT INTO hidden_paths (path) VALUES ('Home.md')");
```
This seeds exactly the two paths that were hardcoded in `config/services.yaml`'s `app.vault.hidden_dirs` before this phase — Task 5 removes that config parameter, so this migration is what preserves today's behavior going forward.

Run:
```bash
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app bin/console doctrine:migrations:migrate --env=test --no-interaction
```
Expected: `hidden_paths` table exists with exactly 2 rows (`A - GM`, `Home.md`) in both databases.

- [ ] **Step 4: Write the failing tests**

`tests/Functional/Controller/Admin/HiddenPathControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use App\Entity\HiddenPath;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class HiddenPathControllerTest extends WebTestCase
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
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\Entity\User')->execute();
        $em->createQuery('DELETE FROM App\Entity\HiddenPath')->execute();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/hidden-paths');

        self::assertResponseRedirects('/login');
    }

    public function testAdminCanAddAndRemoveAHiddenPath(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin();
        $client->loginUser($admin);

        $client->request('GET', '/admin/hidden-paths');
        self::assertResponseIsSuccessful();

        $crawler = $client->getCrawler();
        $form = $crawler->filter('form[action$="/admin/hidden-paths/add"]')->form();
        $client->submit($form, ['path' => 'Locations/Deerwater.md']);

        self::assertResponseRedirects('/admin/hidden-paths');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Locations/Deerwater.md');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hiddenPath = $em->getRepository(HiddenPath::class)->findOneBy(['path' => 'Locations/Deerwater.md']);
        self::assertNotNull($hiddenPath);

        $crawler = $client->getCrawler();
        $form = $crawler->filter('form[action$="/' . $hiddenPath->getId() . '/remove"]')->form();
        $client->submit($form);

        self::assertResponseRedirects('/admin/hidden-paths');
        $client->followRedirect();
        self::assertSelectorTextNotContains('body', 'Locations/Deerwater.md');
    }

    private function createAdmin(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setLabel('admin');
        $user->setUsername('admin');
        $user->setRole('ROLE_ADMIN');
        $user->setPasswordHash($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
```

- [ ] **Step 5: Run tests to verify they fail**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/Admin/HiddenPathControllerTest.php`
Expected: FAIL — `/admin/hidden-paths` doesn't exist yet.

- [ ] **Step 6: Write HiddenPathController**

`src/Controller/Admin/HiddenPathController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\HiddenPath;
use App\Repository\HiddenPathRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/hidden-paths')]
final class HiddenPathController extends AbstractController
{
    public function __construct(
        private readonly HiddenPathRepository $hiddenPaths,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_hidden_paths', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/hidden_paths.html.twig', [
            'hiddenPaths' => $this->hiddenPaths->findBy([], ['path' => 'ASC']),
        ]);
    }

    #[Route('/add', name: 'admin_hidden_paths_add', methods: ['POST'])]
    public function add(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_hidden_paths_add', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $path = trim((string) $request->request->get('path'), " \t\n\r\0\x0B/");

        if ($path !== '' && $this->hiddenPaths->findOneBy(['path' => $path]) === null) {
            $hiddenPath = new HiddenPath();
            $hiddenPath->setPath($path);
            $this->em->persist($hiddenPath);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_hidden_paths');
    }

    #[Route('/{id}/remove', name: 'admin_hidden_paths_remove', methods: ['POST'])]
    public function remove(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_hidden_paths_remove', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $hiddenPath = $this->hiddenPaths->find($id);

        if ($hiddenPath !== null) {
            $this->em->remove($hiddenPath);
            $this->em->flush();
        }

        return $this->redirectToRoute('admin_hidden_paths');
    }
}
```

- [ ] **Step 7: Write the template**

`templates/admin/hidden_paths.html.twig`:
```twig
{% extends 'base.html.twig' %}

{% block title %}Hidden paths — Admin — RPG Notes{% endblock %}

{% block body %}
    <h1>Hidden paths</h1>
    <p>Folders or individual files, relative to the vault root (e.g. "A - GM" or "Locations/Deerwater.md"). Changes take effect on the next sync.</p>

    <form method="post" action="{{ path('admin_hidden_paths_add') }}">
        <label for="path">Path</label>
        <input type="text" id="path" name="path" required>
        <input type="hidden" name="_token" value="{{ csrf_token('admin_hidden_paths_add') }}">
        <button type="submit">Add</button>
    </form>

    <ul>
        {% for hiddenPath in hiddenPaths %}
            <li>
                {{ hiddenPath.path }}
                <form method="post" action="{{ path('admin_hidden_paths_remove', {id: hiddenPath.id}) }}" style="display:inline">
                    <input type="hidden" name="_token" value="{{ csrf_token('admin_hidden_paths_remove') }}">
                    <button type="submit">Remove</button>
                </form>
            </li>
        {% endfor %}
    </ul>
{% endblock %}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/Admin/HiddenPathControllerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 9: Run the full suite**

Run: `docker compose exec app bin/phpunit`
Expected: all tests pass.

- [ ] **Step 10: Commit**

```bash
git add src/Entity/HiddenPath.php src/Repository/HiddenPathRepository.php src/Controller/Admin/HiddenPathController.php templates/admin/hidden_paths.html.twig migrations/ tests/Functional/Controller/Admin/HiddenPathControllerTest.php
git commit -m "Add HiddenPath entity and admin UI, seeded from the current static config"
```

---

### Task 5: Index hidden content instead of skipping it

**Files:**
- Create: `src/Service/Vault/HiddenPathMatcher.php`
- Modify: `src/Entity/Note.php` (add `hidden` field), `src/Service/Markdown/NoteDraft.php` (add `hidden` field)
- Modify: `src/Service/Vault/VaultFileScanner.php`, `src/Service/Vault/NoteIndexer.php`
- Modify: `config/services.yaml` (remove `app.vault.hidden_dirs`)
- New migration (generated — `notes.hidden` column)
- Modify: `tests/Unit/Service/Vault/VaultFileScannerTest.php`, `tests/Integration/Service/Vault/NoteIndexerTest.php`
- Test: `tests/Unit/Service/Vault/HiddenPathMatcherTest.php` (new)

**Interfaces:**
- Produces: `HiddenPathMatcher::isHidden(string $vaultPath, array $hiddenPaths): bool`, consumed by `NoteIndexer`.
- Produces: `Note::isHidden(): bool` / `setHidden(bool): void`, consumed by Task 6.
- Modifies: `VaultFileScanner::scan(string $vaultRoot, array $excludedTopLevelDirs): array` — drops the `$hiddenTopLevelDirs` parameter entirely; hidden-folder filtering moves to `NoteIndexer`.

- [ ] **Step 1: Write the failing HiddenPathMatcher test**

`tests/Unit/Service/Vault/HiddenPathMatcherTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vault;

use App\Service\Vault\HiddenPathMatcher;
use PHPUnit\Framework\TestCase;

final class HiddenPathMatcherTest extends TestCase
{
    private HiddenPathMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new HiddenPathMatcher();
    }

    public function testMatchesExactFilePath(): void
    {
        self::assertTrue($this->matcher->isHidden('Locations/Deerwater.md', ['Locations/Deerwater.md']));
    }

    public function testMatchesFileUnderHiddenTopLevelFolder(): void
    {
        self::assertTrue($this->matcher->isHidden('A - GM/Secrets.md', ['A - GM']));
    }

    public function testMatchesFileUnderHiddenNestedFolder(): void
    {
        self::assertTrue($this->matcher->isHidden(
            'Locations/Settlements/Silverymoon.md',
            ['Locations/Settlements']
        ));
    }

    public function testDoesNotMatchUnrelatedFile(): void
    {
        self::assertFalse($this->matcher->isHidden('People/Malekith.md', ['A - GM']));
    }

    public function testDoesNotFalsePositiveOnPathSegmentPrefix(): void
    {
        // "Locations2" must not be hidden by a "Locations" entry — this is
        // segment equality, not string prefix matching.
        self::assertFalse($this->matcher->isHidden('Locations2/Foo.md', ['Locations']));
    }

    public function testMatchIsCaseInsensitive(): void
    {
        self::assertTrue($this->matcher->isHidden('home.md', ['Home.md']));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Vault/HiddenPathMatcherTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write HiddenPathMatcher**

`src/Service/Vault/HiddenPathMatcher.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Vault;

final class HiddenPathMatcher
{
    /**
     * @param string[] $hiddenPaths
     */
    public function isHidden(string $vaultPath, array $hiddenPaths): bool
    {
        $normalizedHidden = array_map(
            static fn (string $path) => mb_strtolower(trim($path, '/')),
            $hiddenPaths
        );

        $segments = explode('/', $vaultPath);
        $candidate = '';

        foreach ($segments as $segment) {
            $candidate = $candidate === '' ? $segment : $candidate . '/' . $segment;

            if (\in_array(mb_strtolower($candidate), $normalizedHidden, true)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Vault/HiddenPathMatcherTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Add `hidden` to Note and NoteDraft**

Modify `src/Entity/Note.php`, add after the `updatedAt` property:
```php
    #[ORM\Column]
    private bool $hidden = false;
```
And add these methods (e.g. near `getReportNumber`/`setReportNumber`):
```php
    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function setHidden(bool $hidden): void
    {
        $this->hidden = $hidden;
    }
```

Modify `src/Service/Markdown/NoteDraft.php` from:
```php
    public function __construct(
        public readonly string $vaultPath,
        public readonly string $title,
        public string $slug,
        public readonly string $topLevelFolder,
        public string $strippedContent,
        public readonly ?int $reportNumber,
        public readonly ?\DateTimeImmutable $sessionDate,
    ) {
    }
```
to:
```php
    public function __construct(
        public readonly string $vaultPath,
        public readonly string $title,
        public string $slug,
        public readonly string $topLevelFolder,
        public string $strippedContent,
        public readonly ?int $reportNumber,
        public readonly ?\DateTimeImmutable $sessionDate,
        public readonly bool $hidden,
    ) {
    }
```

- [ ] **Step 6: Generate the migration for notes.hidden**

```bash
docker compose exec app bin/console make:migration --no-interaction
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app bin/console doctrine:migrations:migrate --env=test --no-interaction
```
Expected: `notes` table gains a `hidden` boolean column, `NOT NULL DEFAULT false` (existing 428 rows in the dev database get `false`, which is correct — none of them are currently flagged hidden since they were never indexed under the old exclude-at-scan-time behavior; the next sync run, later in this task, is what will actually pick up and flag `A - GM`/`Home.md` content).

- [ ] **Step 7: Update VaultFileScanner**

Modify `src/Service/Vault/VaultFileScanner.php` from:
```php
final class VaultFileScanner
{
    /**
     * @param string[] $excludedTopLevelDirs
     * @param string[] $hiddenTopLevelDirs
     * @return string[] absolute file paths of .md files to index, sorted
     */
    public function scan(string $vaultRoot, array $excludedTopLevelDirs, array $hiddenTopLevelDirs): array
    {
        $vaultRoot = rtrim($vaultRoot, '/');
        $skip = array_map('mb_strtolower', array_merge($excludedTopLevelDirs, $hiddenTopLevelDirs));
```
to:
```php
final class VaultFileScanner
{
    /**
     * @param string[] $excludedTopLevelDirs
     * @return string[] absolute file paths of .md files to index, sorted
     */
    public function scan(string $vaultRoot, array $excludedTopLevelDirs): array
    {
        $vaultRoot = rtrim($vaultRoot, '/');
        $skip = array_map('mb_strtolower', $excludedTopLevelDirs);
```
(the rest of the method is unchanged — it still excludes `.obsidian`/`docs` via `$excludedTopLevelDirs`, it just no longer receives or merges in a separate hidden-dirs list).

- [ ] **Step 8: Update NoteIndexer**

Replace the full contents of `src/Service/Vault/NoteIndexer.php` with:
```php
<?php

declare(strict_types=1);

namespace App\Service\Vault;

use App\Entity\Note;
use App\Repository\HiddenPathRepository;
use App\Repository\NoteRepository;
use App\Service\Markdown\CalloutStripper;
use App\Service\Markdown\FrontmatterStripper;
use App\Service\Markdown\ImagePlaceholderStripper;
use App\Service\Markdown\NoteDraft;
use App\Service\Markdown\ReportFilenameParser;
use App\Service\Markdown\Slugifier;
use App\Service\Markdown\WikilinkIndex;
use App\Service\Markdown\WikilinkTransformer;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\ConverterInterface;

class NoteIndexer
{
    /**
     * @param string[] $excludedTopLevelDirs
     */
    public function __construct(
        private readonly VaultFileScanner $scanner,
        private readonly FrontmatterStripper $frontmatterStripper,
        private readonly CalloutStripper $calloutStripper,
        private readonly ImagePlaceholderStripper $imageStripper,
        private readonly WikilinkTransformer $wikilinkTransformer,
        private readonly Slugifier $slugifier,
        private readonly ReportFilenameParser $reportParser,
        private readonly ConverterInterface $markdownConverter,
        private readonly NoteRepository $notes,
        private readonly HiddenPathRepository $hiddenPaths,
        private readonly HiddenPathMatcher $hiddenPathMatcher,
        private readonly EntityManagerInterface $em,
        private readonly array $excludedTopLevelDirs,
    ) {
    }

    public function index(string $vaultRoot): IndexResult
    {
        $vaultRoot = rtrim($vaultRoot, '/');
        $files = $this->scanner->scan($vaultRoot, $this->excludedTopLevelDirs);
        $hiddenPaths = $this->hiddenPaths->findAllPaths();

        $drafts = array_map(fn (string $path) => $this->buildDraft($vaultRoot, $path, $hiddenPaths), $files);
        $this->resolveSlugCollisions($drafts);

        // Hidden notes ARE indexed (so an admin can browse them) but are
        // never added to the WikilinkIndex, so a wikilink pointing at one
        // renders as plain text for every viewer, admin included — see the
        // spec's "Admin link resolution" decision.
        $visibleDrafts = array_values(array_filter($drafts, static fn (NoteDraft $draft) => !$draft->hidden));
        $index = new WikilinkIndex($visibleDrafts);
        $currentPaths = [];

        foreach ($drafts as $draft) {
            $withLinks = $this->wikilinkTransformer->transform($draft->strippedContent, $index);
            $html = (string) $this->markdownConverter->convert($withLinks);

            $note = $this->notes->findOneByVaultPath($draft->vaultPath) ?? new Note();
            $note->setVaultPath($draft->vaultPath);
            $note->setSlug($draft->slug);
            $note->setTitle($draft->title);
            $note->setTopLevelFolder($draft->topLevelFolder);
            $note->setHtml($html);
            $note->setReportNumber($draft->reportNumber);
            $note->setSessionDate($draft->sessionDate);
            $note->setHidden($draft->hidden);
            $note->setUpdatedAt(new \DateTimeImmutable());

            $this->em->persist($note);
            $currentPaths[] = $draft->vaultPath;
        }

        $stale = $this->notes->findByVaultPathNotIn($currentPaths);
        foreach ($stale as $staleNote) {
            $this->em->remove($staleNote);
        }

        $this->em->flush();

        return new IndexResult(updated: \count($drafts), deleted: \count($stale));
    }

    /**
     * Disambiguates slugs that collide across drafts. Note.slug has a UNIQUE
     * database constraint, so two vault files that slugify to the same value
     * (e.g. filenames differing only in stripped characters) would otherwise
     * make every subsequent sync fail identically. Drafts are processed in a
     * deterministic order (sorted by vault path) and any slug already claimed
     * gets a numeric suffix (-2, -3, ...) appended until it is unique.
     *
     * @param NoteDraft[] $drafts
     */
    private function resolveSlugCollisions(array $drafts): void
    {
        $ordered = $drafts;
        usort($ordered, static fn (NoteDraft $a, NoteDraft $b) => strcmp($a->vaultPath, $b->vaultPath));

        $usedSlugs = [];
        foreach ($ordered as $draft) {
            $baseSlug = $draft->slug;
            $candidate = $baseSlug;
            $suffix = 2;
            while (isset($usedSlugs[$candidate])) {
                $candidate = sprintf('%s-%d', $baseSlug, $suffix);
                ++$suffix;
            }

            $draft->slug = $candidate;
            $usedSlugs[$candidate] = true;
        }
    }

    /**
     * @param string[] $hiddenPaths
     */
    private function buildDraft(string $vaultRoot, string $absolutePath, array $hiddenPaths): NoteDraft
    {
        $vaultPath = ltrim(str_replace($vaultRoot, '', $absolutePath), '/');
        $filename = basename($vaultPath);
        $title = preg_replace('/\.md$/i', '', $filename) ?? $filename;
        $topLevelFolder = explode('/', $vaultPath)[0];

        $raw = file_get_contents($absolutePath);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Unable to read vault file: %s', $absolutePath));
        }

        $stripped = $this->imageStripper->strip(
            $this->calloutStripper->strip(
                $this->frontmatterStripper->strip($raw)
            )
        );

        $reportMeta = $this->reportParser->parse($filename);

        return new NoteDraft(
            vaultPath: $vaultPath,
            title: $title,
            slug: $this->slugifier->slugifyPath($vaultPath),
            topLevelFolder: $topLevelFolder,
            strippedContent: $stripped,
            reportNumber: $reportMeta?->reportNumber,
            sessionDate: $reportMeta?->sessionDate,
            hidden: $this->hiddenPathMatcher->isHidden($vaultPath, $hiddenPaths),
        );
    }
}
```

- [ ] **Step 9: Update services.yaml**

Modify `config/services.yaml` from:
```yaml
parameters:
    app.vault.excluded_dirs: ['.obsidian', 'docs']
    app.vault.hidden_dirs: ['A - GM', 'Home.md']
```
to:
```yaml
parameters:
    app.vault.excluded_dirs: ['.obsidian', 'docs']
```
And from:
```yaml
    App\Service\Vault\NoteIndexer:
        arguments:
            $excludedTopLevelDirs: '%app.vault.excluded_dirs%'
            $hiddenTopLevelDirs: '%app.vault.hidden_dirs%'
```
to:
```yaml
    App\Service\Vault\NoteIndexer:
        arguments:
            $excludedTopLevelDirs: '%app.vault.excluded_dirs%'
```

- [ ] **Step 10: Update VaultFileScannerTest**

Modify `tests/Unit/Service/Vault/VaultFileScannerTest.php` from:
```php
    public function testIncludesContentFilesAndExcludesConfiguredDirs(): void
    {
        $results = $this->scanner->scan($this->vaultRoot, ['.obsidian', 'docs'], ['A - GM']);

        $relative = array_map(
            fn (string $path) => str_replace($this->vaultRoot . '/', '', $path),
            $results
        );

        self::assertContains('People/Malekith.md', $relative);
        self::assertContains('Locations/Deerwater.md', $relative);
        self::assertContains('Reports/1-10/Report-1 1.1.1367 The Beginning.md', $relative);
        self::assertContains('Reports/Tähän mennessä tapahtunutta.md', $relative);

        self::assertNotContains('A - GM/Secrets.md', $relative);
        self::assertStringNotContainsString('.obsidian', implode(',', $relative));
        self::assertStringNotContainsString('docs/ignored.md', implode(',', $relative));
    }

    public function testResultsAreSorted(): void
    {
        $results = $this->scanner->scan($this->vaultRoot, ['.obsidian', 'docs'], ['A - GM']);
        $sorted = $results;
        sort($sorted);

        self::assertSame($sorted, $results);
    }
```
to:
```php
    public function testIncludesContentFilesAndExcludesConfiguredDirs(): void
    {
        $results = $this->scanner->scan($this->vaultRoot, ['.obsidian', 'docs']);

        $relative = array_map(
            fn (string $path) => str_replace($this->vaultRoot . '/', '', $path),
            $results
        );

        self::assertContains('People/Malekith.md', $relative);
        self::assertContains('Locations/Deerwater.md', $relative);
        self::assertContains('Reports/1-10/Report-1 1.1.1367 The Beginning.md', $relative);
        self::assertContains('Reports/Tähän mennessä tapahtunutta.md', $relative);
        // Hidden-folder filtering moved to NoteIndexer (via HiddenPathMatcher)
        // in Phase 2 — the scanner itself no longer excludes it.
        self::assertContains('A - GM/Secrets.md', $relative);

        self::assertStringNotContainsString('.obsidian', implode(',', $relative));
        self::assertStringNotContainsString('docs/ignored.md', implode(',', $relative));
    }

    public function testResultsAreSorted(): void
    {
        $results = $this->scanner->scan($this->vaultRoot, ['.obsidian', 'docs']);
        $sorted = $results;
        sort($sorted);

        self::assertSame($sorted, $results);
    }
```

- [ ] **Step 11: Update NoteIndexerTest**

Modify `tests/Integration/Service/Vault/NoteIndexerTest.php`: add `use App\Entity\HiddenPath;` to the imports, change `setUp()` from:
```php
    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->indexer = $container->get(NoteIndexer::class);
        $this->notes = $container->get(NoteRepository::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->vaultRoot = \dirname(__DIR__, 3) . '/Fixtures/vault';

        // Truncate between tests so runs are independent.
        $this->em->createQuery('DELETE FROM App\Entity\Note')->execute();
    }
```
to:
```php
    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->indexer = $container->get(NoteIndexer::class);
        $this->notes = $container->get(NoteRepository::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->vaultRoot = \dirname(__DIR__, 3) . '/Fixtures/vault';

        // Truncate between tests so runs are independent.
        $this->em->createQuery('DELETE FROM App\Entity\Note')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\HiddenPath')->execute();

        $hiddenPath = new HiddenPath();
        $hiddenPath->setPath('A - GM');
        $this->em->persist($hiddenPath);
        $this->em->flush();
    }
```
and change `tearDown()` from:
```php
    protected function tearDown(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Note')->execute();
        parent::tearDown();
    }
```
to:
```php
    protected function tearDown(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Note')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\HiddenPath')->execute();
        parent::tearDown();
    }
```
and replace the `testIndexesVisibleNotesAndExcludesHiddenFolder` test method from:
```php
    public function testIndexesVisibleNotesAndExcludesHiddenFolder(): void
    {
        $result = $this->indexer->index($this->vaultRoot);

        self::assertSame(4, $result->updated); // Malekith, Deerwater, Report-1, summary
        self::assertSame(0, $result->deleted);
        self::assertNull($this->notes->findOneByVaultPath('A - GM/Secrets.md'));
        self::assertNotNull($this->notes->findOneByVaultPath('People/Malekith.md'));
    }
```
to:
```php
    public function testIndexesAllNotesAndFlagsHiddenOnesInsteadOfExcluding(): void
    {
        $result = $this->indexer->index($this->vaultRoot);

        self::assertSame(5, $result->updated); // Malekith, Deerwater, Report-1, summary, Secrets
        self::assertSame(0, $result->deleted);

        $hidden = $this->notes->findOneByVaultPath('A - GM/Secrets.md');
        self::assertNotNull($hidden);
        self::assertTrue($hidden->isHidden());

        $visible = $this->notes->findOneByVaultPath('People/Malekith.md');
        self::assertNotNull($visible);
        self::assertFalse($visible->isHidden());
    }
```
Leave every other test method in the file unchanged.

- [ ] **Step 12: Run the affected tests to verify they pass**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Vault/VaultFileScannerTest.php tests/Integration/Service/Vault/NoteIndexerTest.php`
Expected: PASS (all tests in both files).

- [ ] **Step 13: Run the full suite**

Run: `docker compose exec app bin/phpunit`
Expected: all tests pass.

- [ ] **Step 14: Commit**

```bash
git add src/Service/Vault/HiddenPathMatcher.php src/Entity/Note.php src/Service/Markdown/NoteDraft.php src/Service/Vault/VaultFileScanner.php src/Service/Vault/NoteIndexer.php config/services.yaml migrations/ tests/Unit/Service/Vault/HiddenPathMatcherTest.php tests/Unit/Service/Vault/VaultFileScannerTest.php tests/Integration/Service/Vault/NoteIndexerTest.php
git commit -m "Index hidden content instead of skipping it, flagged via Note.hidden"
```

---

### Task 6: Role-aware enforcement across the site

**Files:**
- Modify: `src/Repository/NoteRepository.php`, `src/Service/Sidebar/SidebarBuilder.php`
- Modify: `src/Controller/NoteController.php`, `src/Controller/FrontPageController.php`
- Modify: `tests/Integration/Service/Sidebar/SidebarBuilderTest.php`, `tests/Functional/Controller/NoteControllerTest.php`, `tests/Functional/Controller/FrontPageControllerTest.php`

**Interfaces:**
- Modifies every `NoteRepository` listing method to accept a trailing `bool $includeHidden = false` parameter: `findAllForSidebar()`, `findReportsPaginated()`, `countReports()`, `findNewestReport()`, `findPreviousReport()`, `findNextReport()`. `findOneBySlug()` is unchanged (always returns hidden notes too — the caller, `NoteController`, decides whether to 404).
- Modifies `SidebarBuilder::build(bool $includeHidden = false): SidebarNode`.

- [ ] **Step 1: Update NoteRepository**

Modify `src/Repository/NoteRepository.php`'s six listing methods from their current bodies to accept and apply `$includeHidden`. Replace the whole file's contents with:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Note;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Note>
 */
class NoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Note::class);
    }

    public function findOneByVaultPath(string $vaultPath): ?Note
    {
        return $this->findOneBy(['vaultPath' => $vaultPath]);
    }

    /**
     * @param string[] $vaultPaths
     * @return Note[]
     */
    public function findByVaultPathNotIn(array $vaultPaths): array
    {
        $qb = $this->createQueryBuilder('n');

        if ($vaultPaths === []) {
            return $qb->getQuery()->getResult();
        }

        return $qb
            ->where($qb->expr()->notIn('n.vaultPath', ':paths'))
            ->setParameter('paths', $vaultPaths)
            ->getQuery()
            ->getResult();
    }

    public function findOneBySlug(string $slug): ?Note
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return Note[]
     */
    public function findAllForSidebar(bool $includeHidden = false): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.topLevelFolder != :reports')
            ->setParameter('reports', 'Reports')
            ->orderBy('n.vaultPath', 'ASC');

        if (!$includeHidden) {
            $qb->andWhere('n.hidden = false');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Always excludes the single newest report (see findNewestReport()),
     * regardless of page — the caller renders that one separately as the
     * front page's featured report.
     *
     * @return Note[]
     */
    public function findReportsPaginated(int $page, int $perPage, bool $includeHidden = false): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->orderBy('n.reportNumber', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage + 1)
            ->setMaxResults($perPage);

        if (!$includeHidden) {
            $qb->andWhere('n.hidden = false');
        }

        return $qb->getQuery()->getResult();
    }

    public function countReports(bool $includeHidden = false): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.reportNumber IS NOT NULL');

        if (!$includeHidden) {
            $qb->andWhere('n.hidden = false');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findNewestReport(bool $includeHidden = false): ?Note
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->orderBy('n.reportNumber', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults(1);

        if (!$includeHidden) {
            $qb->andWhere('n.hidden = false');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findPreviousReport(int $reportNumber, bool $includeHidden = false): ?Note
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->andWhere('n.reportNumber < :reportNumber')
            ->setParameter('reportNumber', $reportNumber)
            ->orderBy('n.reportNumber', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults(1);

        if (!$includeHidden) {
            $qb->andWhere('n.hidden = false');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findNextReport(int $reportNumber, bool $includeHidden = false): ?Note
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->andWhere('n.reportNumber > :reportNumber')
            ->setParameter('reportNumber', $reportNumber)
            ->orderBy('n.reportNumber', 'ASC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults(1);

        if (!$includeHidden) {
            $qb->andWhere('n.hidden = false');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
```

- [ ] **Step 2: Update SidebarBuilder**

Modify `src/Service/Sidebar/SidebarBuilder.php` from:
```php
    public function build(): SidebarNode
    {
        $root = new SidebarNode('');

        foreach ($this->notes->findAllForSidebar() as $note) {
```
to:
```php
    public function build(bool $includeHidden = false): SidebarNode
    {
        $root = new SidebarNode('');

        foreach ($this->notes->findAllForSidebar($includeHidden) as $note) {
```

- [ ] **Step 3: Update NoteController**

Modify `src/Controller/NoteController.php`'s `__invoke` method from:
```php
    public function __invoke(string $slug): Response
    {
        $note = $this->notes->findOneBySlug($slug);

        if ($note === null) {
            throw $this->createNotFoundException('Note not found.');
        }

        $reportNumber = $note->getReportNumber();

        return $this->render('note/show.html.twig', [
            'note' => $note,
            'previousReport' => $reportNumber !== null ? $this->notes->findPreviousReport($reportNumber) : null,
            'nextReport' => $reportNumber !== null ? $this->notes->findNextReport($reportNumber) : null,
            'sidebar' => $this->sidebar->build(),
        ]);
    }
```
to:
```php
    public function __invoke(string $slug): Response
    {
        $note = $this->notes->findOneBySlug($slug);
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        if ($note === null || ($note->isHidden() && !$isAdmin)) {
            throw $this->createNotFoundException('Note not found.');
        }

        $reportNumber = $note->getReportNumber();

        return $this->render('note/show.html.twig', [
            'note' => $note,
            'previousReport' => $reportNumber !== null ? $this->notes->findPreviousReport($reportNumber, $isAdmin) : null,
            'nextReport' => $reportNumber !== null ? $this->notes->findNextReport($reportNumber, $isAdmin) : null,
            'sidebar' => $this->sidebar->build($isAdmin),
        ]);
    }
```

- [ ] **Step 4: Update FrontPageController**

Modify `src/Controller/FrontPageController.php`'s `__invoke` method from:
```php
    public function __invoke(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        $featuredReport = $page === 1 ? $this->notes->findNewestReport() : null;
        $featuredPreviousReport = $featuredReport !== null
            ? $this->notes->findPreviousReport($featuredReport->getReportNumber())
            : null;

        $reports = $this->notes->findReportsPaginated($page, self::PER_PAGE);
        $total = $this->notes->countReports();
        $listTotal = max(0, $total - 1);

        return $this->render('front_page/index.html.twig', [
            'featuredReport' => $featuredReport,
            'featuredPreviousReport' => $featuredPreviousReport,
            'reports' => $reports,
            'page' => $page,
            'totalPages' => max(1, (int) ceil($listTotal / self::PER_PAGE)),
            'sidebar' => $this->sidebar->build(),
        ]);
    }
```
to:
```php
    public function __invoke(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        $featuredReport = $page === 1 ? $this->notes->findNewestReport($isAdmin) : null;
        $featuredPreviousReport = $featuredReport !== null
            ? $this->notes->findPreviousReport($featuredReport->getReportNumber(), $isAdmin)
            : null;

        $reports = $this->notes->findReportsPaginated($page, self::PER_PAGE, $isAdmin);
        $total = $this->notes->countReports($isAdmin);
        $listTotal = max(0, $total - 1);

        return $this->render('front_page/index.html.twig', [
            'featuredReport' => $featuredReport,
            'featuredPreviousReport' => $featuredPreviousReport,
            'reports' => $reports,
            'page' => $page,
            'totalPages' => max(1, (int) ceil($listTotal / self::PER_PAGE)),
            'sidebar' => $this->sidebar->build($isAdmin),
        ]);
    }
```

- [ ] **Step 5: Write the failing tests**

Append to `tests/Integration/Service/Sidebar/SidebarBuilderTest.php`'s class body (add `use App\Repository\NoteRepository;` is not needed; add nothing new to imports — `Note::setHidden` is already available):
```php
    public function testIncludeHiddenControlsWhetherHiddenNotesAppear(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $builder = $container->get(SidebarBuilder::class);

        $em->createQuery('DELETE FROM App\Entity\Note')->execute();

        $hidden = new Note();
        $hidden->setVaultPath('A - GM/Secrets.md');
        $hidden->setSlug('a-gm/secrets');
        $hidden->setTitle('Secrets');
        $hidden->setTopLevelFolder('A - GM');
        $hidden->setHtml('<p></p>');
        $hidden->setHidden(true);
        $em->persist($hidden);
        $em->flush();

        $rootWithoutHidden = $builder->build(false);
        self::assertArrayNotHasKey('A - GM', $rootWithoutHidden->getFolders());

        $rootWithHidden = $builder->build(true);
        self::assertArrayHasKey('A - GM', $rootWithHidden->getFolders());

        $em->createQuery('DELETE FROM App\Entity\Note')->execute();
    }
```

Append to `tests/Functional/Controller/NoteControllerTest.php`'s class body (add `use App\Entity\User;` and `use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;` to the imports):
```php
    public function testHiddenNoteReturns404ForAnonymousVisitor(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $note = $this->makeHiddenNote($em);

        $client->request('GET', '/notes/a-gm/secret-plot');

        self::assertResponseStatusCodeSame(404);
    }

    public function testHiddenNoteReturns404ForLoggedInPlayer(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->makeHiddenNote($em);
        $player = $this->makeUser($em, 'player-visitor', 'ROLE_PLAYER');

        $client->loginUser($player);
        $client->request('GET', '/notes/a-gm/secret-plot');

        self::assertResponseStatusCodeSame(404);
    }

    public function testHiddenNoteIsVisibleToAdmin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->makeHiddenNote($em);
        $admin = $this->makeUser($em, 'admin-visitor', 'ROLE_ADMIN');

        $client->loginUser($admin);
        $client->request('GET', '/notes/a-gm/secret-plot');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Top secret.');
    }

    private function makeHiddenNote(EntityManagerInterface $em): Note
    {
        $note = new Note();
        $note->setVaultPath('A - GM/Secret Plot.md');
        $note->setSlug('a-gm/secret-plot');
        $note->setTitle('Secret Plot');
        $note->setTopLevelFolder('A - GM');
        $note->setHtml('<p>Top secret.</p>');
        $note->setHidden(true);
        $em->persist($note);
        $em->flush();

        return $note;
    }

    private function makeUser(EntityManagerInterface $em, string $username, string $role): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setLabel($username);
        $user->setUsername($username);
        $user->setRole($role);
        $user->setPasswordHash($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
```
Also change `NoteControllerTest`'s `cleanUpNotes()` private method to also clear `User` rows, since these new tests create them — rename it and update both call sites (`setUp`/`tearDown`) from:
```php
    private function cleanUpNotes(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\Entity\Note')->execute();
    }
```
to:
```php
    private function cleanUpNotes(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM App\Entity\Note')->execute();
        $em->createQuery('DELETE FROM App\Entity\User')->execute();
    }
```

Append to `tests/Functional/Controller/FrontPageControllerTest.php`'s class body (add `use App\Entity\User;` and `use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;` to the imports), and update `cleanUpNotes()` the same way as above (also delete `App\Entity\User`):
```php
    public function testHiddenReportExcludedForAnonymousButShownForAdmin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->makeReport($em, 1, 'report-1', '<p>Visible content.</p>');
        $hidden = $this->makeReport($em, 2, 'report-2', '<p>Hidden content.</p>');
        $hidden->setHidden(true);
        $em->flush();

        $client->request('GET', '/');
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Hidden content.', $content);
        self::assertStringContainsString('Visible content.', $content);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new User();
        $admin->setLabel('front-page-admin');
        $admin->setUsername('front-page-admin');
        $admin->setRole('ROLE_ADMIN');
        $admin->setPasswordHash($hasher->hashPassword($admin, 'password123'));
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);
        $client->request('GET', '/');
        $adminContent = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Hidden content.', $adminContent);
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `docker compose exec app bin/phpunit tests/Integration/Service/Sidebar/SidebarBuilderTest.php tests/Functional/Controller/NoteControllerTest.php tests/Functional/Controller/FrontPageControllerTest.php`
Expected: PASS (all tests in all three files).

- [ ] **Step 7: Run the full suite**

Run: `docker compose exec app bin/phpunit`
Expected: all tests pass.

- [ ] **Step 8: Commit**

```bash
git add src/Repository/NoteRepository.php src/Service/Sidebar/SidebarBuilder.php src/Controller/NoteController.php src/Controller/FrontPageController.php tests/Integration/Service/Sidebar/SidebarBuilderTest.php tests/Functional/Controller/NoteControllerTest.php tests/Functional/Controller/FrontPageControllerTest.php
git commit -m "Enforce admin-only visibility for hidden content across note pages, front page, and sidebar"
```

---

### Task 7: Admin dashboard, navbar Admin link, end-to-end verification

**Files:**
- Create: `src/Controller/Admin/DashboardController.php`, `templates/admin/dashboard.html.twig`
- Modify: `templates/base.html.twig` (Admin navbar link)

**Interfaces:**
- Produces: `admin_dashboard` route, linked from the navbar and from `/admin/players`/`/admin/hidden-paths` (already reference it conceptually — this task makes it real).

- [ ] **Step 1: Write DashboardController and its template**

`src/Controller/Admin/DashboardController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_dashboard')]
final class DashboardController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }
}
```

`templates/admin/dashboard.html.twig`:
```twig
{% extends 'base.html.twig' %}

{% block title %}Admin — RPG Notes{% endblock %}

{% block body %}
    <h1>Admin</h1>
    <ul>
        <li><a href="{{ path('admin_players') }}">Players</a></li>
        <li><a href="{{ path('admin_hidden_paths') }}">Hidden paths</a></li>
    </ul>
{% endblock %}
```

- [ ] **Step 2: Add the navbar Admin link**

Modify `templates/base.html.twig` from:
```twig
        <nav class="navbar-auth">
            {% if app.user %}
                <span>Logged in as {{ app.user.userIdentifier }}</span>
                <a href="{{ path('logout') }}">Log out</a>
            {% else %}
                <a href="{{ path('login') }}">Log in</a>
            {% endif %}
        </nav>
```
to:
```twig
        <nav class="navbar-auth">
            {% if app.user %}
                <span>Logged in as {{ app.user.userIdentifier }}</span>
                {% if is_granted('ROLE_ADMIN') %}
                    <a href="{{ path('admin_dashboard') }}">Admin</a>
                {% endif %}
                <a href="{{ path('logout') }}">Log out</a>
            {% else %}
                <a href="{{ path('login') }}">Log in</a>
            {% endif %}
        </nav>
```

- [ ] **Step 3: Run the full suite**

Run: `docker compose exec app bin/phpunit`
Expected: all tests pass.

- [ ] **Step 4: End-to-end verification against the real synced vault**

```bash
docker compose exec app bin/console app:create-admin
```
(enter a real username/password when prompted)

```bash
docker compose exec app bin/console app:sync
```
Expected: the sync now picks up `A - GM/*` and `Home.md` (previously never indexed at all) and flags them hidden, per the `HiddenPath` rows seeded in Task 4.

Then, with the docker stack running:
- `curl -s -o /dev/null -w '%{http_code}' http://localhost:8091/notes/home` → `404` (still hidden for anonymous, same as before this phase).
- Log in as the admin via the browser at `http://localhost:8091/login`, then visit `http://localhost:8091/notes/home` → 200, content renders.
- As admin, visit `http://localhost:8091/` and confirm the sidebar now shows an "A - GM" folder (absent when logged out).
- Visit `http://localhost:8091/admin/players`, add a test player, generate an invite link, open it in a private/incognito window, complete registration, confirm login works with the new credentials, confirm that player does NOT see "A - GM" in the sidebar and gets a 404 for `/notes/home`.
- Visit `http://localhost:8091/admin/hidden-paths`, confirm "A - GM" and "Home.md" are listed (seeded by Task 4's migration).

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Admin/DashboardController.php templates/admin/dashboard.html.twig templates/base.html.twig
git commit -m "Add admin dashboard and navbar Admin link"
```

---

## Self-Review Notes

- **Spec coverage:** admin bootstrap via console command (Task 1) · invite-only registration doubling as password reset (Task 2) · admin players UI (Task 3) · admin-editable hidden paths, folders and individual files at any depth, seeded from the old static config (Task 4) · hidden content indexed instead of skipped, admin-only visibility, wikilinks into hidden content stay plain text for everyone (Task 5) · role-aware enforcement everywhere content is listed or looked up (Task 6) · admin dashboard + navbar (Task 7) — every spec goal has a task.
- **Placeholder scan:** no TBD/TODO; every step has literal code, exact commands, or precisely-described manual verification (browser steps for the end-to-end check, which can't be scripted, consistent with how JS/visual verification was handled in prior plans).
- **Type consistency:** `User`/`HiddenPath` entity shapes are defined once (Task 1, Task 4) and consumed identically by every later task. The `bool $includeHidden = false` parameter is added consistently to all six `NoteRepository` listing methods and threaded through `SidebarBuilder::build()`, `NoteController`, and `FrontPageController` with the same name and default in Task 6 — no method got a different parameter name or a different default.
- **Known limitation carried from the spec:** wikilinks into hidden content never become real links, not even for admins — this is unchanged by this plan (Task 5 explicitly preserves it) and was an explicit, deliberate scope decision, not an oversight.
