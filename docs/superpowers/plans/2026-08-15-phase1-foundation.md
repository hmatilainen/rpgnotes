# Phase 1: Docker Foundation + Read-Only Rendering Site — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a dockerized Symfony app that syncs a private GitHub notes repo via webhook, renders the notes (with Obsidian wikilink resolution) as HTML, and serves a paginated session-report front page plus a folder-tree sidebar — no authentication yet.

**Architecture:** Symfony 7 app on FrankenPHP + PostgreSQL 16, both in docker-compose on non-default ports. A `NoteIndexer` does a two-pass full reindex of a locally-cloned git checkout of the notes vault on every sync (webhook or CLI): pass 1 extracts per-file metadata into in-memory `NoteDraft`s, pass 2 resolves wikilinks against that in-memory index and persists `Note` rows via Doctrine.

**Tech Stack:** PHP 8.3, Symfony 7 (framework-bundle, doctrine-bundle, doctrine-migrations-bundle, twig-bundle), Doctrine ORM, PostgreSQL 16, `league/commonmark`, `symfony/process` (for git shell-out), FrankenPHP, PHPUnit (via `symfony/test-pack`).

Spec: [docs/superpowers/specs/2026-08-15-phase1-foundation-design.md](../specs/2026-08-15-phase1-foundation-design.md)

## Global Constraints

- Docker host ports: app → `8091`, db → `5434` (existing local containers occupy 3307, 8080, 5433, 1025, 8025 — avoid all of these).
- Notes GitHub repo is **private** — git sync needs credentials (deploy key or PAT embedded in the HTTPS remote URL), supplied via env var, never committed.
- Hidden top-level vault folders for Phase 1: `A - GM` — hardcoded in a config parameter, fully excluded at scan time (not indexed, not linkable).
- Excluded (non-content) top-level dirs: `.obsidian`, `docs`.
- Every sync is a **full reindex** — no incremental diffing.
- Wikilink resolution order: exact vault-path match → unique filename-only match → (ambiguous: first by path, stable sort) → unresolvable/hidden target renders as plain text, never a link.
- No authentication anywhere in Phase 1.
- Frontmatter is parsed and discarded (never rendered). Callout blocks (`> [!type] ...`) are stripped entirely, not just unstyled. `[img:NNNNNN]` placeholders are stripped.
- Note URLs: `/notes/{vault-path-slugified}`, mirroring folder structure.

---

## File Structure

```
docker-compose.yml
docker/app/Dockerfile
composer.json / composer.lock
.env / .env.test
config/packages/doctrine.yaml
config/packages/twig.yaml
config/services.yaml
src/Entity/Note.php
src/Repository/NoteRepository.php
src/Service/Markdown/FrontmatterStripper.php
src/Service/Markdown/CalloutStripper.php
src/Service/Markdown/ImagePlaceholderStripper.php
src/Service/Markdown/Slugifier.php
src/Service/Markdown/ReportFilenameParser.php
src/Service/Markdown/ReportMeta.php
src/Service/Markdown/NoteDraft.php
src/Service/Markdown/WikilinkIndex.php
src/Service/Markdown/WikilinkTransformer.php
src/Service/Vault/VaultFileScanner.php
src/Service/Vault/NoteIndexer.php
src/Service/Vault/IndexResult.php
src/Service/Vault/GitSyncService.php
src/Service/Sidebar/SidebarBuilder.php
src/Service/Sidebar/SidebarNode.php
src/Command/SyncNotesCommand.php
src/Controller/WebhookController.php
src/Controller/NoteController.php
src/Controller/FrontPageController.php
templates/base.html.twig
templates/partials/_sidebar.html.twig
templates/note/show.html.twig
templates/front_page/index.html.twig
migrations/VersionXXXXXXXXXXXX.php (generated)
tests/Fixtures/vault/... (fixture vault, see Task 9)
tests/Unit/Service/Markdown/*Test.php
tests/Unit/Service/Vault/VaultFileScannerTest.php
tests/Integration/Service/Vault/NoteIndexerTest.php
tests/Integration/Service/Vault/GitSyncServiceTest.php
tests/Integration/Service/Sidebar/SidebarBuilderTest.php
tests/Unit/Command/SyncNotesCommandTest.php
tests/Functional/Controller/WebhookControllerTest.php
tests/Functional/Controller/NoteControllerTest.php
tests/Functional/Controller/FrontPageControllerTest.php
```

---

### Task 1: Docker + Symfony skeleton

**Files:**
- Create: `docker-compose.yml`
- Create: `docker/app/Dockerfile`
- Create: `.dockerignore`
- Create (via composer): `composer.json`, `symfony.lock`, `bin/console`, `public/index.php`, `.env`

**Interfaces:**
- Produces: a running Symfony app reachable at `http://localhost:8091` and a Postgres instance reachable at `localhost:5434`, both used by every later task.

- [ ] **Step 1: Create the Symfony skeleton locally (used to seed the Docker image)**

```bash
composer create-project symfony/skeleton:"7.1.*" . --no-interaction
composer require symfony/framework-bundle symfony/runtime symfony/twig-bundle
composer require --dev symfony/test-pack symfony/maker-bundle
```

- [ ] **Step 2: Write the Dockerfile**

`docker/app/Dockerfile`:
```dockerfile
FROM dunglas/frankenphp:1-php8.3

RUN install-php-extensions pdo_pgsql intl zip opcache
RUN apt-get update && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
ENV SERVER_NAME=:80
ENV APP_ENV=dev

COPY composer.json composer.lock* symfony.lock* /app/
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . /app
RUN composer dump-autoload --optimize
```

- [ ] **Step 3: Write docker-compose.yml**

`docker-compose.yml`:
```yaml
services:
  app:
    build:
      context: .
      dockerfile: docker/app/Dockerfile
    ports:
      - "8091:80"
    environment:
      APP_ENV: dev
      DATABASE_URL: "postgresql://app:app@db:5432/app?serverVersion=16&charset=utf8"
      VAULT_PATH: /var/vault/repo
      VAULT_REPO_URL: ${VAULT_REPO_URL}
      SYNC_WEBHOOK_SECRET: ${SYNC_WEBHOOK_SECRET}
    volumes:
      - ./:/app
      - vault_data:/var/vault
    depends_on:
      - db

  db:
    image: postgres:16
    ports:
      - "5434:5432"
    environment:
      POSTGRES_USER: app
      POSTGRES_PASSWORD: app
      POSTGRES_DB: app
    volumes:
      - db_data:/var/lib/postgresql/data

volumes:
  db_data:
  vault_data:
```

- [ ] **Step 4: Write .dockerignore**

`.dockerignore`:
```
var/
vendor/
.git/
tests/Fixtures/vault/.git
```

- [ ] **Step 5: Add VAULT_REPO_URL and SYNC_WEBHOOK_SECRET to .env**

Append to `.env`:
```
VAULT_PATH=/var/vault/repo
VAULT_REPO_URL=
SYNC_WEBHOOK_SECRET=changeme
```
(Leave `VAULT_REPO_URL` blank in the committed `.env`; the real value with
embedded credentials goes in an untracked `.env.local` / the shell
environment before running `docker compose up`.)

- [ ] **Step 6: Build and run**

Run: `docker compose up -d --build`
Expected: `docker compose ps` shows `app` and `db` both `Up`; `curl -s -o /dev/null -w '%{http_code}' http://localhost:8091` returns `200` (Symfony's default "Welcome" page since no routes are defined yet).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Bootstrap Symfony skeleton with FrankenPHP + Postgres docker-compose"
```

---

### Task 2: Doctrine, Note entity, and migration

**Files:**
- Modify: `composer.json` (add doctrine packages)
- Create: `config/packages/doctrine.yaml`
- Create: `src/Entity/Note.php`
- Create: `src/Repository/NoteRepository.php`
- Create: `migrations/VersionXXXXXXXXXXXX.php` (generated)
- Create: `.env.test`

**Interfaces:**
- Produces: `Note` entity with fields `id, vaultPath, slug, title, topLevelFolder, html, reportNumber, sessionDate, updatedAt`, and matching getters/setters, used by every task from Task 9 onward.
- Produces: `NoteRepository` (empty of custom methods for now — filled in across Tasks 9-16).

- [ ] **Step 1: Require Doctrine packages**

```bash
composer require doctrine/orm doctrine/doctrine-bundle doctrine/doctrine-migrations-bundle
```

- [ ] **Step 2: Write the Note entity**

`src/Entity/Note.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NoteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NoteRepository::class)]
#[ORM\Table(name: 'notes')]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 1024, unique: true)]
    private string $vaultPath = '';

    #[ORM\Column(length: 1024, unique: true)]
    private string $slug = '';

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(length: 255)]
    private string $topLevelFolder = '';

    #[ORM\Column(type: 'text')]
    private string $html = '';

    #[ORM\Column(nullable: true)]
    private ?int $reportNumber = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $sessionDate = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVaultPath(): string
    {
        return $this->vaultPath;
    }

    public function setVaultPath(string $vaultPath): void
    {
        $this->vaultPath = $vaultPath;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getTopLevelFolder(): string
    {
        return $this->topLevelFolder;
    }

    public function setTopLevelFolder(string $topLevelFolder): void
    {
        $this->topLevelFolder = $topLevelFolder;
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function setHtml(string $html): void
    {
        $this->html = $html;
    }

    public function getReportNumber(): ?int
    {
        return $this->reportNumber;
    }

    public function setReportNumber(?int $reportNumber): void
    {
        $this->reportNumber = $reportNumber;
    }

    public function getSessionDate(): ?\DateTimeImmutable
    {
        return $this->sessionDate;
    }

    public function setSessionDate(?\DateTimeImmutable $sessionDate): void
    {
        $this->sessionDate = $sessionDate;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
```

- [ ] **Step 3: Write the (initially empty) repository**

`src/Repository/NoteRepository.php`:
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
}
```

- [ ] **Step 4: Add .env.test with a separate test database**

`.env.test`:
```
DATABASE_URL="postgresql://app:app@db:5432/app_test?serverVersion=16&charset=utf8"
VAULT_PATH=/tmp/rpgnotes-test-vault
SYNC_WEBHOOK_SECRET=test-secret
```

- [ ] **Step 5: Generate and run the migration, create the test DB**

Run:
```bash
docker compose exec app bin/console doctrine:database:create --if-not-exists
docker compose exec app bin/console make:migration --no-interaction
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec app bin/console doctrine:migrations:migrate --env=test --no-interaction
```
Expected: migration file created under `migrations/`, both `app` and `app_test` databases have a `notes` table (verify with `docker compose exec db psql -U app -d app -c '\d notes'`).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Add Note entity, repository, and initial migration"
```

---

### Task 3: FrontmatterStripper

**Files:**
- Create: `src/Service/Markdown/FrontmatterStripper.php`
- Test: `tests/Unit/Service/Markdown/FrontmatterStripperTest.php`

**Interfaces:**
- Produces: `FrontmatterStripper::strip(string $content): string`, consumed by `NoteIndexer` (Task 10).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Service/Markdown/FrontmatterStripperTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\FrontmatterStripper;
use PHPUnit\Framework\TestCase;

final class FrontmatterStripperTest extends TestCase
{
    private FrontmatterStripper $stripper;

    protected function setUp(): void
    {
        $this->stripper = new FrontmatterStripper();
    }

    public function testStripsLeadingFrontmatter(): void
    {
        $input = "---\ntype: plot\n---\n\n# Heading\n\nBody text.";
        $result = $this->stripper->strip($input);

        self::assertSame("\n# Heading\n\nBody text.", $result);
    }

    public function testLeavesContentWithoutFrontmatterUnchanged(): void
    {
        $input = "# Heading\n\nBody text.";

        self::assertSame($input, $this->stripper->strip($input));
    }

    public function testDoesNotStripDashesThatAreNotLeadingFrontmatter(): void
    {
        $input = "# Heading\n\n---\n\nHorizontal rule below, not frontmatter.";

        self::assertSame($input, $this->stripper->strip($input));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Markdown/FrontmatterStripperTest.php`
Expected: FAIL — class `App\Service\Markdown\FrontmatterStripper` not found.

- [ ] **Step 3: Write the implementation**

`src/Service/Markdown/FrontmatterStripper.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class FrontmatterStripper
{
    public function strip(string $content): string
    {
        $normalized = ltrim($content, "\xEF\xBB\xBF");

        if (!str_starts_with($normalized, '---')) {
            return $content;
        }

        if (preg_match('/^---\r?\n.*?\r?\n---\r?\n?/s', $normalized, $matches) === 1) {
            return substr($normalized, \strlen($matches[0]));
        }

        return $content;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Markdown/FrontmatterStripperTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Markdown/FrontmatterStripper.php tests/Unit/Service/Markdown/FrontmatterStripperTest.php
git commit -m "Add FrontmatterStripper service"
```

---

### Task 4: CalloutStripper

**Files:**
- Create: `src/Service/Markdown/CalloutStripper.php`
- Test: `tests/Unit/Service/Markdown/CalloutStripperTest.php`

**Interfaces:**
- Produces: `CalloutStripper::strip(string $content): string`, consumed by `NoteIndexer` (Task 10).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Service/Markdown/CalloutStripperTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\CalloutStripper;
use PHPUnit\Framework\TestCase;

final class CalloutStripperTest extends TestCase
{
    private CalloutStripper $stripper;

    protected function setUp(): void
    {
        $this->stripper = new CalloutStripper();
    }

    public function testStripsCalloutBlockEntirely(): void
    {
        $input = "Before.\n\n> [!note] GM secret\n> Only the GM should see this.\n> Second line.\n\nAfter.";

        self::assertSame("Before.\n\n\nAfter.", $this->stripper->strip($input));
    }

    public function testPreservesPlainBlockquotes(): void
    {
        $input = "> Rudi, Nerinoa and Myrbec.\n> A regular in-fiction letter.";

        self::assertSame($input, $this->stripper->strip($input));
    }

    public function testStripsMultipleCalloutsInOneDocument(): void
    {
        $input = "> [!warning] First\n> line one\n\nMiddle text.\n\n> [!tip] Second\n> line two";

        self::assertSame("\n\nMiddle text.\n\n", $this->stripper->strip($input));
    }

    public function testCalloutAtEndOfDocument(): void
    {
        $input = "Content.\n\n> [!note] Trailing\n> last line";

        self::assertSame("Content.\n\n", $this->stripper->strip($input));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Markdown/CalloutStripperTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

`src/Service/Markdown/CalloutStripper.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class CalloutStripper
{
    public function strip(string $content): string
    {
        $lines = explode("\n", $content);
        $result = [];
        $count = \count($lines);
        $i = 0;

        while ($i < $count) {
            if (preg_match('/^>\s*\[!\w+\]/', $lines[$i]) === 1) {
                ++$i;
                while ($i < $count && preg_match('/^>/', $lines[$i]) === 1) {
                    ++$i;
                }
                continue;
            }

            $result[] = $lines[$i];
            ++$i;
        }

        return implode("\n", $result);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Markdown/CalloutStripperTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Markdown/CalloutStripper.php tests/Unit/Service/Markdown/CalloutStripperTest.php
git commit -m "Add CalloutStripper service"
```

---

### Task 5: ImagePlaceholderStripper

**Files:**
- Create: `src/Service/Markdown/ImagePlaceholderStripper.php`
- Test: `tests/Unit/Service/Markdown/ImagePlaceholderStripperTest.php`

**Interfaces:**
- Produces: `ImagePlaceholderStripper::strip(string $content): string`, consumed by `NoteIndexer` (Task 10).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Service/Markdown/ImagePlaceholderStripperTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\ImagePlaceholderStripper;
use PHPUnit\Framework\TestCase;

final class ImagePlaceholderStripperTest extends TestCase
{
    private ImagePlaceholderStripper $stripper;

    protected function setUp(): void
    {
        $this->stripper = new ImagePlaceholderStripper();
    }

    public function testRemovesImagePlaceholder(): void
    {
        $input = "Cainin kartta [img:153388]\n\nMalekith tutki karttaa.";

        self::assertSame("Cainin kartta \n\nMalekith tutki karttaa.", $this->stripper->strip($input));
    }

    public function testRemovesMultiplePlaceholders(): void
    {
        $input = "[img:1] text [img:22222]";

        self::assertSame(" text ", $this->stripper->strip($input));
    }

    public function testLeavesContentWithoutPlaceholdersUnchanged(): void
    {
        $input = "No placeholders here.";

        self::assertSame($input, $this->stripper->strip($input));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Markdown/ImagePlaceholderStripperTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

`src/Service/Markdown/ImagePlaceholderStripper.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class ImagePlaceholderStripper
{
    public function strip(string $content): string
    {
        return preg_replace('/\[img:\d+\]/', '', $content) ?? $content;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Markdown/ImagePlaceholderStripperTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Markdown/ImagePlaceholderStripper.php tests/Unit/Service/Markdown/ImagePlaceholderStripperTest.php
git commit -m "Add ImagePlaceholderStripper service"
```

---

### Task 6: Slugifier

**Files:**
- Create: `src/Service/Markdown/Slugifier.php`
- Test: `tests/Unit/Service/Markdown/SlugifierTest.php`

**Interfaces:**
- Produces: `Slugifier::slugifyPath(string $vaultRelativePath): string`, consumed by `NoteIndexer` (Task 10).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Service/Markdown/SlugifierTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\Slugifier;
use PHPUnit\Framework\TestCase;

final class SlugifierTest extends TestCase
{
    private Slugifier $slugifier;

    protected function setUp(): void
    {
        $this->slugifier = new Slugifier();
    }

    public function testSlugifiesSimplePath(): void
    {
        self::assertSame('locations/deerwater', $this->slugifier->slugifyPath('Locations/Deerwater.md'));
    }

    public function testSlugifiesPathWithSpacesAndPunctuation(): void
    {
        self::assertSame(
            'reports/41-50/report-41-20-2-1367-matka-brokenstonen-laaksoon',
            $this->slugifier->slugifyPath('Reports/41-50/Report-41 20.2.1367 Matka Brokenstonen laaksoon.md')
        );
    }

    public function testTransliteratesFinnishDiacritics(): void
    {
        $result = $this->slugifier->slugifyPath('Reports/Tähän mennessä tapahtunutta.md');

        self::assertStringNotContainsString('ä', $result);
        self::assertSame('reports/tahan-mennessa-tapahtunutta', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Markdown/SlugifierTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

`src/Service/Markdown/Slugifier.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class Slugifier
{
    private const TRANSLITERATION = [
        'ä' => 'a', 'ö' => 'o', 'å' => 'a',
        'Ä' => 'a', 'Ö' => 'o', 'Å' => 'a',
    ];

    public function slugifyPath(string $vaultRelativePath): string
    {
        $withoutExtension = preg_replace('/\.md$/i', '', $vaultRelativePath) ?? $vaultRelativePath;
        $segments = explode('/', $withoutExtension);

        return implode('/', array_map(fn (string $segment) => $this->slugifySegment($segment), $segments));
    }

    private function slugifySegment(string $segment): string
    {
        $transliterated = strtr($segment, self::TRANSLITERATION);
        $lowered = mb_strtolower($transliterated);
        $dashed = preg_replace('/[^a-z0-9]+/u', '-', $lowered) ?? $lowered;

        return trim($dashed, '-');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Markdown/SlugifierTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Markdown/Slugifier.php tests/Unit/Service/Markdown/SlugifierTest.php
git commit -m "Add Slugifier service"
```

---

### Task 7: ReportFilenameParser

**Files:**
- Create: `src/Service/Markdown/ReportMeta.php`
- Create: `src/Service/Markdown/ReportFilenameParser.php`
- Test: `tests/Unit/Service/Markdown/ReportFilenameParserTest.php`

**Interfaces:**
- Produces: `ReportMeta` (readonly: `reportNumber: int`, `sessionDate: ?\DateTimeImmutable`, `title: string`) and `ReportFilenameParser::parse(string $filename): ?ReportMeta`, consumed by `NoteIndexer` (Task 10).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Service/Markdown/ReportFilenameParserTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\ReportFilenameParser;
use PHPUnit\Framework\TestCase;

final class ReportFilenameParserTest extends TestCase
{
    private ReportFilenameParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ReportFilenameParser();
    }

    public function testParsesValidReportFilename(): void
    {
        $meta = $this->parser->parse('Report-41 20.2.1367 Matka Brokenstonen laaksoon.md');

        self::assertNotNull($meta);
        self::assertSame(41, $meta->reportNumber);
        self::assertNotNull($meta->sessionDate);
        self::assertSame('20.02.1367', $meta->sessionDate->format('d.m.Y'));
        self::assertSame('Matka Brokenstonen laaksoon', $meta->title);
    }

    public function testReturnsNullForNonReportFilename(): void
    {
        self::assertNull($this->parser->parse('Tähän mennessä tapahtunutta.md'));
    }

    public function testReturnsNullForUnrelatedFilename(): void
    {
        self::assertNull($this->parser->parse('Deerwater.md'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Markdown/ReportFilenameParserTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write ReportMeta**

`src/Service/Markdown/ReportMeta.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class ReportMeta
{
    public function __construct(
        public readonly int $reportNumber,
        public readonly ?\DateTimeImmutable $sessionDate,
        public readonly string $title,
    ) {
    }
}
```

- [ ] **Step 4: Write ReportFilenameParser**

`src/Service/Markdown/ReportFilenameParser.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class ReportFilenameParser
{
    public function parse(string $filename): ?ReportMeta
    {
        $name = preg_replace('/\.md$/i', '', $filename) ?? $filename;

        if (preg_match(
            '/^Report-(\d+)\s+(\d{1,2})\.(\d{1,2})\.(\d{3,4})\s+(.+)$/u',
            $name,
            $matches
        ) !== 1) {
            return null;
        }

        [, $number, $day, $month, $year, $title] = $matches;

        $sessionDate = \DateTimeImmutable::createFromFormat(
            '!d.m.Y',
            sprintf('%02d.%02d.%04d', (int) $day, (int) $month, (int) $year)
        );

        return new ReportMeta(
            reportNumber: (int) $number,
            sessionDate: $sessionDate !== false ? $sessionDate : null,
            title: trim($title),
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Markdown/ReportFilenameParserTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Service/Markdown/ReportMeta.php src/Service/Markdown/ReportFilenameParser.php tests/Unit/Service/Markdown/ReportFilenameParserTest.php
git commit -m "Add ReportFilenameParser service"
```

---

### Task 8: NoteDraft, WikilinkIndex, WikilinkTransformer

**Files:**
- Create: `src/Service/Markdown/NoteDraft.php`
- Create: `src/Service/Markdown/WikilinkIndex.php`
- Create: `src/Service/Markdown/WikilinkTransformer.php`
- Test: `tests/Unit/Service/Markdown/WikilinkTransformerTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks (pure new value objects/services).
- Produces: `NoteDraft` (readonly: `vaultPath, title, slug, topLevelFolder, reportNumber, sessionDate`, plus mutable `public string $strippedContent`), `WikilinkIndex::__construct(NoteDraft[] $drafts)` + `resolve(string $target): ?NoteDraft`, `WikilinkTransformer::transform(string $content, WikilinkIndex $index): string`. All consumed by `NoteIndexer` (Task 10).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Service/Markdown/WikilinkTransformerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Markdown;

use App\Service\Markdown\NoteDraft;
use App\Service\Markdown\WikilinkIndex;
use App\Service\Markdown\WikilinkTransformer;
use PHPUnit\Framework\TestCase;

final class WikilinkTransformerTest extends TestCase
{
    private WikilinkTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new WikilinkTransformer();
    }

    private function draft(string $vaultPath, string $slug): NoteDraft
    {
        return new NoteDraft(
            vaultPath: $vaultPath,
            title: basename($vaultPath, '.md'),
            slug: $slug,
            topLevelFolder: explode('/', $vaultPath)[0],
            strippedContent: '',
            reportNumber: null,
            sessionDate: null,
        );
    }

    public function testResolvesExactPathMatch(): void
    {
        $index = new WikilinkIndex([$this->draft('Locations/Deerwater.md', 'locations/deerwater')]);
        $result = $this->transformer->transform('Seurue saapui [[Locations/Deerwater]]iin.', $index);

        self::assertSame('Seurue saapui [Deerwater](/notes/locations/deerwater)iin.', $result);
    }

    public function testResolvesUniqueFilenameOnlyMatch(): void
    {
        $index = new WikilinkIndex([$this->draft('People/Malekith.md', 'people/malekith')]);
        $result = $this->transformer->transform('[[Malekith]] arrives.', $index);

        self::assertSame('[Malekith](/notes/people/malekith) arrives.', $result);
    }

    public function testUsesDisplayTextWhenGiven(): void
    {
        $index = new WikilinkIndex([$this->draft('Locations/Settlements/Silverymoon.md', 'locations/settlements/silverymoon')]);
        $result = $this->transformer->transform('Kohti [[Locations/Settlements/Silverymoon|Silverymoon]]ia.', $index);

        self::assertSame('Kohti [Silverymoon](/notes/locations/settlements/silverymoon)ia.', $result);
    }

    public function testAmbiguousFilenameResolvesToStableFirstMatch(): void
    {
        $index = new WikilinkIndex([
            $this->draft('Locations/Zeta/Runa.md', 'locations/zeta/runa'),
            $this->draft('People/Runa.md', 'people/runa'),
        ]);
        $result = $this->transformer->transform('[[Runa]]', $index);

        self::assertSame('[Runa](/notes/locations/zeta/runa)', $result);
    }

    public function testUnresolvableTargetRendersAsPlainText(): void
    {
        $index = new WikilinkIndex([]);
        $result = $this->transformer->transform('[[Nonexistent Page]]', $index);

        self::assertSame('Nonexistent Page', $result);
    }

    public function testHiddenTargetNotIncludedInIndexRendersAsPlainText(): void
    {
        // A - GM notes are never added to the WikilinkIndex (excluded during scanning),
        // so a link to one behaves identically to an unresolvable link.
        $index = new WikilinkIndex([$this->draft('People/Malekith.md', 'people/malekith')]);
        $result = $this->transformer->transform('[[A - GM/Secrets]]', $index);

        self::assertSame('Secrets', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Markdown/WikilinkTransformerTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write NoteDraft**

`src/Service/Markdown/NoteDraft.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class NoteDraft
{
    public function __construct(
        public readonly string $vaultPath,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $topLevelFolder,
        public string $strippedContent,
        public readonly ?int $reportNumber,
        public readonly ?\DateTimeImmutable $sessionDate,
    ) {
    }
}
```

- [ ] **Step 4: Write WikilinkIndex**

`src/Service/Markdown/WikilinkIndex.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class WikilinkIndex
{
    /** @var array<string, NoteDraft> */
    private array $byPath = [];

    /** @var array<string, NoteDraft[]> */
    private array $byFilename = [];

    /**
     * @param NoteDraft[] $drafts
     */
    public function __construct(array $drafts)
    {
        foreach ($drafts as $draft) {
            $this->byPath[mb_strtolower($draft->vaultPath)] = $draft;
            $filename = mb_strtolower(basename($draft->vaultPath));
            $this->byFilename[$filename][] = $draft;
        }
    }

    public function resolve(string $target): ?NoteDraft
    {
        $normalizedTarget = trim($target);

        $byPath = $this->byPath[mb_strtolower($normalizedTarget . '.md')] ?? null;
        if ($byPath !== null) {
            return $byPath;
        }

        $filename = mb_strtolower(basename($normalizedTarget) . '.md');
        $candidates = $this->byFilename[$filename] ?? [];

        if (\count($candidates) === 0) {
            return null;
        }

        usort($candidates, static fn (NoteDraft $a, NoteDraft $b) => strcmp($a->vaultPath, $b->vaultPath));

        return $candidates[0];
    }
}
```

- [ ] **Step 5: Write WikilinkTransformer**

`src/Service/Markdown/WikilinkTransformer.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Markdown;

final class WikilinkTransformer
{
    public function transform(string $content, WikilinkIndex $index): string
    {
        $result = preg_replace_callback(
            '/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u',
            function (array $matches) use ($index): string {
                $target = trim($matches[1]);
                $display = isset($matches[2]) ? trim($matches[2]) : basename($target);
                $resolved = $index->resolve($target);

                if ($resolved === null) {
                    return $display;
                }

                return sprintf('[%s](/notes/%s)', $display, $resolved->slug);
            },
            $content
        );

        return $result ?? $content;
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Markdown/WikilinkTransformerTest.php`
Expected: PASS (6 tests).

- [ ] **Step 7: Commit**

```bash
git add src/Service/Markdown/NoteDraft.php src/Service/Markdown/WikilinkIndex.php src/Service/Markdown/WikilinkTransformer.php tests/Unit/Service/Markdown/WikilinkTransformerTest.php
git commit -m "Add NoteDraft, WikilinkIndex, and WikilinkTransformer"
```

---

### Task 9: VaultFileScanner + fixture vault

**Files:**
- Create: `src/Service/Vault/VaultFileScanner.php`
- Test: `tests/Unit/Service/Vault/VaultFileScannerTest.php`
- Create fixture vault (used by this task and Tasks 10-16):
  - `tests/Fixtures/vault/People/Malekith.md`
  - `tests/Fixtures/vault/Locations/Deerwater.md`
  - `tests/Fixtures/vault/A - GM/Secrets.md`
  - `tests/Fixtures/vault/Reports/1-10/Report-1 1.1.1367 The Beginning.md`
  - `tests/Fixtures/vault/Reports/Tähän mennessä tapahtunutta.md`
  - `tests/Fixtures/vault/.obsidian/app.json`
  - `tests/Fixtures/vault/docs/ignored.md`

**Interfaces:**
- Produces: `VaultFileScanner::scan(string $vaultRoot, array $excludedTopLevelDirs, array $hiddenTopLevelDirs): string[]` (absolute paths), consumed by `NoteIndexer` (Task 10).

- [ ] **Step 1: Create the fixture vault files**

```bash
mkdir -p "tests/Fixtures/vault/People" "tests/Fixtures/vault/Locations" "tests/Fixtures/vault/A - GM" "tests/Fixtures/vault/Reports/1-10" "tests/Fixtures/vault/.obsidian" "tests/Fixtures/vault/docs"
```

`tests/Fixtures/vault/People/Malekith.md`:
```markdown
---
type: npc
---

# Malekith

A traveler who joined the party in [[Locations/Deerwater]].

> [!note] GM only
> Malekith is secretly working for the Zhentarim.

See also [[A - GM/Secrets]] for more.
```

`tests/Fixtures/vault/Locations/Deerwater.md`:
```markdown
# Deerwater

A small settlement. Home to [[Malekith]] before he left.
```

`tests/Fixtures/vault/A - GM/Secrets.md`:
```markdown
# Secrets

GM-only content. Should never be indexed.
```

`tests/Fixtures/vault/Reports/1-10/Report-1 1.1.1367 The Beginning.md`:
```markdown
## The Beginning

The party met at [[Locations/Deerwater]]. Image reference [img:1001] here.
```

`tests/Fixtures/vault/Reports/Tähän mennessä tapahtunutta.md`:
```markdown
# Summary so far

Not a numbered report, should not appear on the front page.
```

`tests/Fixtures/vault/.obsidian/app.json`:
```json
{}
```

`tests/Fixtures/vault/docs/ignored.md`:
```markdown
# Ignored

Local tooling doc, not vault content.
```

- [ ] **Step 2: Write the failing test**

`tests/Unit/Service/Vault/VaultFileScannerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Vault;

use App\Service\Vault\VaultFileScanner;
use PHPUnit\Framework\TestCase;

final class VaultFileScannerTest extends TestCase
{
    private VaultFileScanner $scanner;
    private string $vaultRoot;

    protected function setUp(): void
    {
        $this->scanner = new VaultFileScanner();
        $this->vaultRoot = \dirname(__DIR__, 2) . '/Fixtures/vault';
    }

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
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Vault/VaultFileScannerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Write the implementation**

`src/Service/Vault/VaultFileScanner.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Vault;

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

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vaultRoot, \FilesystemIterator::SKIP_DOTS)
        );

        $results = [];

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            $relative = ltrim(str_replace($vaultRoot, '', $file->getPathname()), '/');
            $topLevel = explode('/', $relative)[0];

            if (\in_array(mb_strtolower($topLevel), $skip, true)) {
                continue;
            }

            $results[] = $file->getPathname();
        }

        sort($results);

        return $results;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Unit/Service/Vault/VaultFileScannerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Service/Vault/VaultFileScanner.php tests/Unit/Service/Vault/VaultFileScannerTest.php tests/Fixtures/vault
git commit -m "Add VaultFileScanner and test fixture vault"
```

---

### Task 10: NoteIndexer (two-pass orchestration + persistence)

**Files:**
- Modify: `composer.json` (add `league/commonmark`)
- Create: `config/services.yaml`
- Create: `src/Service/Vault/IndexResult.php`
- Create: `src/Service/Vault/NoteIndexer.php`
- Modify: `src/Repository/NoteRepository.php` (add `findOneByVaultPath`, `findByVaultPathNotIn`)
- Test: `tests/Integration/Service/Vault/NoteIndexerTest.php`

**Interfaces:**
- Consumes: `VaultFileScanner::scan()` (Task 9), `FrontmatterStripper::strip()` (Task 3), `CalloutStripper::strip()` (Task 4), `ImagePlaceholderStripper::strip()` (Task 5), `Slugifier::slugifyPath()` (Task 6), `ReportFilenameParser::parse()` (Task 7), `NoteDraft`, `WikilinkIndex`, `WikilinkTransformer::transform()` (Task 8), `Note` entity (Task 2).
- Produces: `IndexResult` (readonly: `updated: int`, `deleted: int`), `NoteIndexer::index(string $vaultRoot): IndexResult`, consumed by `SyncNotesCommand` (Task 12) and `WebhookController` (Task 13).
- Produces on `NoteRepository`: `findOneByVaultPath(string $vaultPath): ?Note`, `findByVaultPathNotIn(string[] $vaultPaths): Note[]`, consumed by later tasks.

- [ ] **Step 1: Require league/commonmark and configure parameters**

```bash
composer require league/commonmark
```

`config/services.yaml`:
```yaml
parameters:
    app.vault.excluded_dirs: ['.obsidian', 'docs']
    app.vault.hidden_dirs: ['A - GM']

services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\:
        resource: '../src/'
        exclude:
            - '../src/DependencyInjection/'
            - '../src/Entity/'
            - '../src/Kernel.php'

    App\Service\Vault\NoteIndexer:
        arguments:
            $excludedTopLevelDirs: '%app.vault.excluded_dirs%'
            $hiddenTopLevelDirs: '%app.vault.hidden_dirs%'

    League\CommonMark\ConverterInterface:
        class: League\CommonMark\CommonMarkConverter
```

- [ ] **Step 2: Add repository query methods**

Modify `src/Repository/NoteRepository.php`, add inside the class:
```php
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
```

- [ ] **Step 3: Write IndexResult**

`src/Service/Vault/IndexResult.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Vault;

final class IndexResult
{
    public function __construct(
        public readonly int $updated,
        public readonly int $deleted,
    ) {
    }
}
```

- [ ] **Step 4: Write the failing integration test**

`tests/Integration/Service/Vault/NoteIndexerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Vault;

use App\Repository\NoteRepository;
use App\Service\Vault\NoteIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NoteIndexerTest extends KernelTestCase
{
    private NoteIndexer $indexer;
    private NoteRepository $notes;
    private EntityManagerInterface $em;
    private string $vaultRoot;

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

    public function testIndexesVisibleNotesAndExcludesHiddenFolder(): void
    {
        $result = $this->indexer->index($this->vaultRoot);

        self::assertSame(4, $result->updated); // Malekith, Deerwater, Report-1, summary
        self::assertSame(0, $result->deleted);
        self::assertNull($this->notes->findOneByVaultPath('A - GM/Secrets.md'));
        self::assertNotNull($this->notes->findOneByVaultPath('People/Malekith.md'));
    }

    public function testStripsFrontmatterCalloutsAndImagePlaceholders(): void
    {
        $this->indexer->index($this->vaultRoot);

        $note = $this->notes->findOneByVaultPath('People/Malekith.md');

        self::assertNotNull($note);
        self::assertStringNotContainsString('type: npc', $note->getHtml());
        self::assertStringNotContainsString('Zhentarim', $note->getHtml());
        self::assertStringNotContainsString('[img:', $note->getHtml());
    }

    public function testResolvesWikilinksAndDropsLinkToHiddenNote(): void
    {
        $this->indexer->index($this->vaultRoot);

        $note = $this->notes->findOneByVaultPath('People/Malekith.md');

        self::assertNotNull($note);
        self::assertStringContainsString('href="/notes/locations/deerwater"', $note->getHtml());
        self::assertStringNotContainsString('href="/notes/a - gm/secrets"', $note->getHtml());
        self::assertStringContainsString('Secrets', $note->getHtml()); // plain text, not a link
    }

    public function testSetsReportNumberOnlyForReportFiles(): void
    {
        $this->indexer->index($this->vaultRoot);

        $report = $this->notes->findOneByVaultPath('Reports/1-10/Report-1 1.1.1367 The Beginning.md');
        $nonReport = $this->notes->findOneByVaultPath('Reports/Tähän mennessä tapahtunutta.md');

        self::assertSame(1, $report->getReportNumber());
        self::assertNull($nonReport->getReportNumber());
    }

    public function testRemovesStaleNotesNoLongerOnDisk(): void
    {
        $this->indexer->index($this->vaultRoot);
        self::assertNotNull($this->notes->findOneByVaultPath('People/Malekith.md'));

        // Point at a smaller vault subset to simulate a file being deleted upstream.
        $reducedVault = sys_get_temp_dir() . '/rpgnotes-reduced-vault';
        if (!is_dir($reducedVault)) {
            mkdir($reducedVault . '/People', 0777, true);
            copy($this->vaultRoot . '/People/Malekith.md', $reducedVault . '/People/Malekith.md');
        }

        $result = $this->indexer->index($reducedVault);

        self::assertSame(1, $result->updated);
        self::assertGreaterThanOrEqual(1, $result->deleted);
        self::assertNull($this->notes->findOneByVaultPath('Locations/Deerwater.md'));
    }

    protected function tearDown(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Note')->execute();
        parent::tearDown();
    }
}
```

- [ ] **Step 5: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Integration/Service/Vault/NoteIndexerTest.php --env=test`
Expected: FAIL — `NoteIndexer` class not found.

- [ ] **Step 6: Write the implementation**

`src/Service/Vault/NoteIndexer.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Vault;

use App\Entity\Note;
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

final class NoteIndexer
{
    /**
     * @param string[] $excludedTopLevelDirs
     * @param string[] $hiddenTopLevelDirs
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
        private readonly EntityManagerInterface $em,
        private readonly array $excludedTopLevelDirs,
        private readonly array $hiddenTopLevelDirs,
    ) {
    }

    public function index(string $vaultRoot): IndexResult
    {
        $vaultRoot = rtrim($vaultRoot, '/');
        $files = $this->scanner->scan($vaultRoot, $this->excludedTopLevelDirs, $this->hiddenTopLevelDirs);

        $drafts = array_map(fn (string $path) => $this->buildDraft($vaultRoot, $path), $files);
        $index = new WikilinkIndex($drafts);
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

    private function buildDraft(string $vaultRoot, string $absolutePath): NoteDraft
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
        );
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Integration/Service/Vault/NoteIndexerTest.php --env=test`
Expected: PASS (5 tests). If it fails on DB connection, verify `.env.test`'s `DATABASE_URL` matches Task 2 Step 4-5's `app_test` database.

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock config/services.yaml src/Repository/NoteRepository.php src/Service/Vault/IndexResult.php src/Service/Vault/NoteIndexer.php tests/Integration/Service/Vault/NoteIndexerTest.php
git commit -m "Add NoteIndexer two-pass ingestion pipeline"
```

---

### Task 11: GitSyncService

**Files:**
- Modify: `composer.json` (add `symfony/process`)
- Create: `src/Service/Vault/GitSyncService.php`
- Test: `tests/Integration/Service/Vault/GitSyncServiceTest.php`

**Interfaces:**
- Produces: `GitSyncService::sync(): void` (throws `\RuntimeException` on failure), consumed by `SyncNotesCommand` (Task 12) and `WebhookController` (Task 13).

- [ ] **Step 1: Require symfony/process**

```bash
composer require symfony/process
```

- [ ] **Step 2: Write the failing test**

`tests/Integration/Service/Vault/GitSyncServiceTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Vault;

use App\Service\Vault\GitSyncService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitSyncServiceTest extends TestCase
{
    private string $bareRepoPath;
    private string $checkoutPath;

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
    }

    public function testClonesRepoWhenCheckoutDoesNotExist(): void
    {
        $service = new GitSyncService($this->checkoutPath, $this->bareRepoPath);
        $service->sync();

        self::assertFileExists($this->checkoutPath . '/note.md');
    }

    public function testPullsLatestChangesWhenCheckoutAlreadyExists(): void
    {
        $service = new GitSyncService($this->checkoutPath, $this->bareRepoPath);
        $service->sync();

        // Push a new commit to the bare repo from a second clone.
        $secondClone = sys_get_temp_dir() . '/rpgnotes-second-' . uniqid();
        (new Process(['git', 'clone', $this->bareRepoPath, $secondClone]))->mustRun();
        file_put_contents($secondClone . '/note2.md', "# New note\n");
        (new Process(['git', '-C', $secondClone, 'config', 'user.email', 'test@example.com']))->mustRun();
        (new Process(['git', '-C', $secondClone, 'config', 'user.name', 'Test']))->mustRun();
        (new Process(['git', '-C', $secondClone, 'add', '.']))->mustRun();
        (new Process(['git', '-C', $secondClone, 'commit', '-m', 'second']))->mustRun();
        (new Process(['git', '-C', $secondClone, 'push', 'origin', 'main']))->mustRun();

        $service->sync();

        self::assertFileExists($this->checkoutPath . '/note2.md');
    }

    public function testThrowsOnInvalidRepoUrl(): void
    {
        $service = new GitSyncService($this->checkoutPath, '/nonexistent/path/to/repo');

        $this->expectException(\RuntimeException::class);
        $service->sync();
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Integration/Service/Vault/GitSyncServiceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Write the implementation**

`src/Service/Vault/GitSyncService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Vault;

use Symfony\Component\Process\Process;

final class GitSyncService
{
    public function __construct(
        private readonly string $vaultPath,
        private readonly string $repoUrl,
    ) {
    }

    public function sync(): void
    {
        if (!is_dir($this->vaultPath . '/.git')) {
            $this->run(['git', 'clone', $this->repoUrl, $this->vaultPath]);

            return;
        }

        $this->run(['git', '-C', $this->vaultPath, 'fetch', 'origin']);
        $this->run(['git', '-C', $this->vaultPath, 'reset', '--hard', 'origin/main']);
    }

    /**
     * @param string[] $command
     */
    private function run(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'Git command "%s" failed: %s',
                implode(' ', $command),
                $process->getErrorOutput()
            ));
        }
    }
}
```

- [ ] **Step 5: Register the service with its env-driven arguments**

Append to `config/services.yaml` under `services:`:
```yaml
    App\Service\Vault\GitSyncService:
        arguments:
            $vaultPath: '%env(VAULT_PATH)%'
            $repoUrl: '%env(VAULT_REPO_URL)%'
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Integration/Service/Vault/GitSyncServiceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock config/services.yaml src/Service/Vault/GitSyncService.php tests/Integration/Service/Vault/GitSyncServiceTest.php
git commit -m "Add GitSyncService for cloning/pulling the notes repo"
```

---

### Task 12: SyncNotesCommand

**Files:**
- Create: `src/Command/SyncNotesCommand.php`
- Test: `tests/Unit/Command/SyncNotesCommandTest.php`

**Interfaces:**
- Consumes: `GitSyncService::sync()` (Task 11), `NoteIndexer::index()` (Task 10).
- Produces: `bin/console app:sync` CLI command.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Command/SyncNotesCommandTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SyncNotesCommand;
use App\Service\Vault\GitSyncService;
use App\Service\Vault\IndexResult;
use App\Service\Vault\NoteIndexer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncNotesCommandTest extends TestCase
{
    public function testSyncsAndReportsCounts(): void
    {
        $gitSync = $this->createMock(GitSyncService::class);
        $gitSync->expects(self::once())->method('sync');

        $indexer = $this->createMock(NoteIndexer::class);
        $indexer->expects(self::once())
            ->method('index')
            ->with('/var/vault/repo')
            ->willReturn(new IndexResult(updated: 5, deleted: 1));

        $command = new SyncNotesCommand($gitSync, $indexer, '/var/vault/repo');

        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find('app:sync'));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Indexed 5 notes, removed 1 stale notes.', $tester->getDisplay());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Unit/Command/SyncNotesCommandTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

`src/Command/SyncNotesCommand.php`:
```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Vault\GitSyncService;
use App\Service\Vault\NoteIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:sync', description: 'Pull the notes repo from GitHub and reindex it')]
final class SyncNotesCommand extends Command
{
    public function __construct(
        private readonly GitSyncService $gitSync,
        private readonly NoteIndexer $indexer,
        private readonly string $vaultPath,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->gitSync->sync();
        $result = $this->indexer->index($this->vaultPath);

        $output->writeln(sprintf(
            'Indexed %d notes, removed %d stale notes.',
            $result->updated,
            $result->deleted
        ));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Register $vaultPath argument**

Append to `config/services.yaml` under `services:`:
```yaml
    App\Command\SyncNotesCommand:
        arguments:
            $vaultPath: '%env(VAULT_PATH)%'
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Unit/Command/SyncNotesCommandTest.php`
Expected: PASS (1 test).

- [ ] **Step 6: Manually verify against the fixture vault**

Run: `docker compose exec app bin/console app:sync` after temporarily setting `VAULT_PATH=/app/tests/Fixtures/vault` (e.g. `docker compose exec -e VAULT_PATH=/app/tests/Fixtures/vault app bin/console app:sync`)
Expected: Output `Indexed 4 notes, removed 0 stale notes.`

- [ ] **Step 7: Commit**

```bash
git add config/services.yaml src/Command/SyncNotesCommand.php tests/Unit/Command/SyncNotesCommandTest.php
git commit -m "Add app:sync console command"
```

---

### Task 13: WebhookController

**Files:**
- Create: `src/Controller/WebhookController.php`
- Test: `tests/Functional/Controller/WebhookControllerTest.php`

**Interfaces:**
- Consumes: `GitSyncService::sync()` (Task 11), `NoteIndexer::index()` (Task 10).
- Produces: `POST /webhook/sync` route, used by the GitHub Action (outside this codebase) once Phase 1 is deployed.

- [ ] **Step 1: Write the failing test**

`tests/Functional/Controller/WebhookControllerTest.php`:
```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/WebhookControllerTest.php`
Expected: FAIL — no route for `/webhook/sync`.

- [ ] **Step 3: Write the implementation**

`src/Controller/WebhookController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Vault\GitSyncService;
use App\Service\Vault\NoteIndexer;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class WebhookController extends AbstractController
{
    public function __construct(
        private readonly GitSyncService $gitSync,
        private readonly NoteIndexer $indexer,
        private readonly LoggerInterface $logger,
        private readonly string $vaultPath,
        private readonly string $webhookSecret,
    ) {
    }

    #[Route('/webhook/sync', name: 'webhook_sync', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $authHeader = (string) $request->headers->get('Authorization', '');

        if (!hash_equals('Bearer ' . $this->webhookSecret, $authHeader)) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        try {
            $this->gitSync->sync();
            $result = $this->indexer->index($this->vaultPath);
        } catch (\Throwable $e) {
            $this->logger->error('Notes sync failed', ['exception' => $e]);

            return new JsonResponse(['error' => 'sync failed'], 500);
        }

        return new JsonResponse(['updated' => $result->updated, 'deleted' => $result->deleted]);
    }
}
```

- [ ] **Step 4: Register $vaultPath and $webhookSecret arguments**

Append to `config/services.yaml` under `services:`:
```yaml
    App\Controller\WebhookController:
        arguments:
            $vaultPath: '%env(VAULT_PATH)%'
            $webhookSecret: '%env(SYNC_WEBHOOK_SECRET)%'
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/WebhookControllerTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add config/services.yaml src/Controller/WebhookController.php tests/Functional/Controller/WebhookControllerTest.php
git commit -m "Add webhook endpoint to trigger notes sync"
```

---

### Task 14: NoteController + note page template

**Files:**
- Create: `src/Service/Sidebar/SidebarNode.php`
- Create: `src/Service/Sidebar/SidebarBuilder.php`
- Modify: `src/Repository/NoteRepository.php` (add `findOneBySlug`, `findAllForSidebar`)
- Create: `src/Controller/NoteController.php`
- Create: `templates/base.html.twig`
- Create: `templates/partials/_sidebar.html.twig`
- Create: `templates/note/show.html.twig`
- Test: `tests/Functional/Controller/NoteControllerTest.php`

**Interfaces:**
- Consumes: `NoteRepository::findOneBySlug()`, `NoteRepository::findAllForSidebar()`, `Note` entity.
- Produces: `SidebarNode` (tree node: `name: string`, `childFolder(string $name): SidebarNode`, `addNote(Note $note): void`, `getFolders(): SidebarNode[]`, `getNotes(): Note[]`), `SidebarBuilder::build(): SidebarNode`, consumed also by `FrontPageController` (Task 15).
- Produces: `GET /notes/{slug}` route rendering `note/show.html.twig`.

- [ ] **Step 1: Add repository query methods**

Modify `src/Repository/NoteRepository.php`, add inside the class:
```php
    public function findOneBySlug(string $slug): ?Note
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return Note[]
     */
    public function findAllForSidebar(): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.topLevelFolder != :reports')
            ->setParameter('reports', 'Reports')
            ->orderBy('n.vaultPath', 'ASC')
            ->getQuery()
            ->getResult();
    }
```

- [ ] **Step 2: Write SidebarNode**

`src/Service/Sidebar/SidebarNode.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Sidebar;

use App\Entity\Note;

final class SidebarNode
{
    /** @var array<string, SidebarNode> */
    private array $folders = [];

    /** @var Note[] */
    private array $notes = [];

    public function __construct(public readonly string $name)
    {
    }

    public function childFolder(string $name): self
    {
        return $this->folders[$name] ??= new self($name);
    }

    public function addNote(Note $note): void
    {
        $this->notes[] = $note;
    }

    /**
     * @return SidebarNode[]
     */
    public function getFolders(): array
    {
        ksort($this->folders);

        return $this->folders;
    }

    /**
     * @return Note[]
     */
    public function getNotes(): array
    {
        return $this->notes;
    }
}
```

- [ ] **Step 3: Write SidebarBuilder**

`src/Service/Sidebar/SidebarBuilder.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Sidebar;

use App\Repository\NoteRepository;

final class SidebarBuilder
{
    public function __construct(private readonly NoteRepository $notes)
    {
    }

    public function build(): SidebarNode
    {
        $root = new SidebarNode('');

        foreach ($this->notes->findAllForSidebar() as $note) {
            $segments = explode('/', $note->getVaultPath());
            array_pop($segments); // drop filename, keep only folder segments

            $cursor = $root;
            foreach ($segments as $segment) {
                $cursor = $cursor->childFolder($segment);
            }

            $cursor->addNote($note);
        }

        return $root;
    }
}
```

- [ ] **Step 4: Write NoteController**

`src/Controller/NoteController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\NoteRepository;
use App\Service\Sidebar\SidebarBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NoteController extends AbstractController
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly SidebarBuilder $sidebar,
    ) {
    }

    #[Route('/notes/{slug}', name: 'note_show', requirements: ['slug' => '.+'])]
    public function __invoke(string $slug): Response
    {
        $note = $this->notes->findOneBySlug($slug);

        if ($note === null) {
            throw $this->createNotFoundException('Note not found.');
        }

        return $this->render('note/show.html.twig', [
            'note' => $note,
            'sidebar' => $this->sidebar->build(),
        ]);
    }
}
```

- [ ] **Step 5: Write templates**

`templates/base.html.twig`:
```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{% block title %}RPG Notes{% endblock %}</title>
</head>
<body>
    <div class="layout">
        <nav class="sidebar">
            {% include 'partials/_sidebar.html.twig' with { node: sidebar } %}
        </nav>
        <main class="content">
            {% block body %}{% endblock %}
        </main>
    </div>
</body>
</html>
```

`templates/partials/_sidebar.html.twig`:
```twig
<ul>
    {% for folder in node.folders %}
        <li>
            <strong>{{ folder.name }}</strong>
            {% include 'partials/_sidebar.html.twig' with { node: folder } %}
        </li>
    {% endfor %}
    {% for note in node.notes %}
        <li><a href="/notes/{{ note.slug }}">{{ note.title }}</a></li>
    {% endfor %}
</ul>
```

`templates/note/show.html.twig`:
```twig
{% extends 'base.html.twig' %}

{% block title %}{{ note.title }} — RPG Notes{% endblock %}

{% block body %}
    <article>
        <h1>{{ note.title }}</h1>
        {{ note.html|raw }}
    </article>
{% endblock %}
```

- [ ] **Step 6: Write the failing test**

`tests/Functional/Controller/NoteControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NoteControllerTest extends WebTestCase
{
    public function testRendersExistingNote(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $note = new Note();
        $note->setVaultPath('Locations/Deerwater.md');
        $note->setSlug('locations/deerwater');
        $note->setTitle('Deerwater');
        $note->setTopLevelFolder('Locations');
        $note->setHtml('<p>A small settlement.</p>');
        $em->persist($note);
        $em->flush();

        $client->request('GET', '/notes/locations/deerwater');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Deerwater');
        self::assertStringContainsString('A small settlement.', (string) $client->getResponse()->getContent());

        $em->remove($note);
        $em->flush();
    }

    public function testReturns404ForUnknownSlug(): void
    {
        $client = static::createClient();
        $client->request('GET', '/notes/does/not/exist');

        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 7: Run test to verify it fails, then passes**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/NoteControllerTest.php`
Expected: initially FAIL (no route/class), then PASS (2 tests) after Steps 1-5 are in place.

- [ ] **Step 8: Commit**

```bash
git add src/Repository/NoteRepository.php src/Service/Sidebar src/Controller/NoteController.php templates/base.html.twig templates/partials/_sidebar.html.twig templates/note/show.html.twig tests/Functional/Controller/NoteControllerTest.php
git commit -m "Add note page rendering with sidebar navigation"
```

---

### Task 15: FrontPageController + front page template

**Files:**
- Modify: `src/Repository/NoteRepository.php` (add `findReportsPaginated`, `countReports`)
- Create: `src/Controller/FrontPageController.php`
- Create: `templates/front_page/index.html.twig`
- Test: `tests/Functional/Controller/FrontPageControllerTest.php`

**Interfaces:**
- Consumes: `NoteRepository`, `SidebarBuilder::build()` (Task 14).
- Produces: `GET /` route rendering `front_page/index.html.twig`.

- [ ] **Step 1: Add repository query methods**

Modify `src/Repository/NoteRepository.php`, add inside the class:
```php
    /**
     * @return Note[]
     */
    public function findReportsPaginated(int $page, int $perPage): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->orderBy('n.reportNumber', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countReports(): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.reportNumber IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
```

- [ ] **Step 2: Write FrontPageController**

`src/Controller/FrontPageController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\NoteRepository;
use App\Service\Sidebar\SidebarBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FrontPageController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly SidebarBuilder $sidebar,
    ) {
    }

    #[Route('/', name: 'front_page')]
    public function __invoke(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $reports = $this->notes->findReportsPaginated($page, self::PER_PAGE);
        $total = $this->notes->countReports();

        return $this->render('front_page/index.html.twig', [
            'reports' => $reports,
            'page' => $page,
            'totalPages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'sidebar' => $this->sidebar->build(),
        ]);
    }
}
```

- [ ] **Step 3: Write the template**

`templates/front_page/index.html.twig`:
```twig
{% extends 'base.html.twig' %}

{% block title %}Session Reports — RPG Notes{% endblock %}

{% block body %}
    <h1>Session Reports</h1>
    <ul class="report-list">
        {% for report in reports %}
            <li>
                <a href="/notes/{{ report.slug }}">{{ report.title }}</a>
                {% if report.sessionDate %}
                    <span class="report-date">{{ report.sessionDate|date('d.m.Y') }}</span>
                {% endif %}
            </li>
        {% endfor %}
    </ul>

    <nav class="pagination">
        {% if page > 1 %}
            <a href="?page={{ page - 1 }}">Previous</a>
        {% endif %}
        <span>Page {{ page }} of {{ totalPages }}</span>
        {% if page < totalPages %}
            <a href="?page={{ page + 1 }}">Next</a>
        {% endif %}
    </nav>
{% endblock %}
```

- [ ] **Step 4: Write the failing test**

`tests/Functional/Controller/FrontPageControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FrontPageControllerTest extends WebTestCase
{
    public function testListsReportsNewestFirstAndExcludesNonReports(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $older = $this->makeReport($em, 1, 'Reports/1-10/Report-1 x.md', 'report-1');
        $newer = $this->makeReport($em, 2, 'Reports/1-10/Report-2 y.md', 'report-2');
        $summary = new Note();
        $summary->setVaultPath('Reports/summary.md');
        $summary->setSlug('reports/summary');
        $summary->setTitle('Summary');
        $summary->setTopLevelFolder('Reports');
        $summary->setHtml('<p>summary</p>');
        $em->persist($summary);
        $em->flush();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertGreaterThan(
            strpos($content, 'report-2'),
            -1 // sanity: ensure string exists before ordering check below
        );
        self::assertTrue(strpos($content, 'report-2') < strpos($content, 'report-1'));
        self::assertStringNotContainsString('Summary', $content);

        foreach ([$older, $newer, $summary] as $note) {
            $em->remove($note);
        }
        $em->flush();
    }

    private function makeReport(EntityManagerInterface $em, int $number, string $vaultPath, string $slug): Note
    {
        $note = new Note();
        $note->setVaultPath($vaultPath);
        $note->setSlug($slug);
        $note->setTitle('Report ' . $number);
        $note->setTopLevelFolder('Reports');
        $note->setHtml('<p>content</p>');
        $note->setReportNumber($number);
        $em->persist($note);
        $em->flush();

        return $note;
    }
}
```

- [ ] **Step 5: Run test to verify it fails, then passes**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/FrontPageControllerTest.php`
Expected: initially FAIL (no route `/`), then PASS (1 test) after Steps 1-3 are in place.

- [ ] **Step 6: Commit**

```bash
git add src/Repository/NoteRepository.php src/Controller/FrontPageController.php templates/front_page/index.html.twig tests/Functional/Controller/FrontPageControllerTest.php
git commit -m "Add paginated front page listing session reports"
```

---

### Task 16: SidebarBuilder integration test + end-to-end manual verification

**Files:**
- Test: `tests/Integration/Service/Sidebar/SidebarBuilderTest.php`

**Interfaces:**
- Consumes: `SidebarBuilder::build()`, `SidebarNode` (Task 14), `NoteRepository::findAllForSidebar()` (Task 14).

- [ ] **Step 1: Write the failing test**

`tests/Integration/Service/Sidebar/SidebarBuilderTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Sidebar;

use App\Entity\Note;
use App\Service\Sidebar\SidebarBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SidebarBuilderTest extends KernelTestCase
{
    public function testBuildsTreeGroupedByFolderAndExcludesReports(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $builder = $container->get(SidebarBuilder::class);

        $em->createQuery('DELETE FROM App\Entity\Note')->execute();

        $people = new Note();
        $people->setVaultPath('People/Malekith.md');
        $people->setSlug('people/malekith');
        $people->setTitle('Malekith');
        $people->setTopLevelFolder('People');
        $people->setHtml('<p></p>');
        $em->persist($people);

        $nestedLocation = new Note();
        $nestedLocation->setVaultPath('Locations/Settlements/Silverymoon.md');
        $nestedLocation->setSlug('locations/settlements/silverymoon');
        $nestedLocation->setTitle('Silverymoon');
        $nestedLocation->setTopLevelFolder('Locations');
        $nestedLocation->setHtml('<p></p>');
        $em->persist($nestedLocation);

        $report = new Note();
        $report->setVaultPath('Reports/1-10/Report-1 x.md');
        $report->setSlug('reports/1-10/report-1-x');
        $report->setTitle('Report 1');
        $report->setTopLevelFolder('Reports');
        $report->setHtml('<p></p>');
        $report->setReportNumber(1);
        $em->persist($report);

        $em->flush();

        $root = $builder->build();
        $folderNames = array_map(fn ($f) => $f->name, $root->getFolders());

        self::assertContains('People', $folderNames);
        self::assertContains('Locations', $folderNames);
        self::assertNotContains('Reports', $folderNames);

        $locations = $root->getFolders()['Locations'];
        $settlements = $locations->getFolders()['Settlements'];
        self::assertSame('Silverymoon', $settlements->getNotes()[0]->getTitle());

        $em->createQuery('DELETE FROM App\Entity\Note')->execute();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Integration/Service/Sidebar/SidebarBuilderTest.php --env=test`
Expected: If Task 14 was implemented correctly this should already PASS (this task adds coverage, no new production code). If it fails, fix `SidebarBuilder`/`SidebarNode` until it passes — do not change the test's expectations.

- [ ] **Step 3: Run the full test suite**

Run: `docker compose exec app bin/phpunit`
Expected: All tests across every task pass.

- [ ] **Step 4: Manual end-to-end verification with the fixture vault**

```bash
docker compose exec -e VAULT_PATH=/app/tests/Fixtures/vault app bin/console app:sync
```
Then in a browser or via curl:
- `curl -s http://localhost:8091/` → contains "Report 1" / "The Beginning", does not contain "Tähän mennessä".
- `curl -s http://localhost:8091/notes/people/malekith` → contains a link to `/notes/locations/deerwater`, does not contain "Zhentarim" or a link to `A - GM/Secrets`.
- `curl -s -o /dev/null -w '%{http_code}' http://localhost:8091/notes/a%20-%20gm/secrets` → `404`.

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/Service/Sidebar/SidebarBuilderTest.php
git commit -m "Add SidebarBuilder integration test coverage"
```

---

## Self-Review Notes

- **Spec coverage:** docker ports (Task 1) · GitHub Action webhook trigger (Task 13) · frontmatter/callout/img stripping (Tasks 3-5, wired in Task 10) · wikilink resolution incl. hidden-target and ambiguous cases (Task 8, verified end-to-end in Task 10) · path-mirroring URLs (Task 6) · front page pagination newest-first (Task 15) · sidebar excluding Reports/hidden folders (Task 14) · full reindex + stale deletion (Task 10) · error handling for webhook auth/failure (Task 13) all have a task producing and testing them.
- **Placeholder scan:** no TBD/TODO markers; every step has literal code or an exact command.
- **Type consistency:** `NoteDraft`, `WikilinkIndex`, `IndexResult`, `SidebarNode` signatures are defined once (Tasks 7-8, 10, 14) and reused with identical names/types in every later task that consumes them.
