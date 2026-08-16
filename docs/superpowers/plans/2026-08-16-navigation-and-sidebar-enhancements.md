# Navigation & Sidebar Enhancements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist sidebar expand/collapse state via localStorage, hide the `Home` note, feature the newest session report in full on the front page, and add Previous/Next session navigation.

**Architecture:** Two new `NoteRepository` query methods plus one modified pagination method back the new front-page and note-page navigation; a small vanilla-JS file adds client-side persistence to the existing `<details>`-based sidebar; hiding `Home.md` is a one-line config addition reusing the existing hidden-folder mechanism.

**Tech Stack:** Symfony 7.4 controllers/repositories, Twig templates, plain CSS, plain vanilla JavaScript (no framework, no build step). Docker stack already running (`docker compose up -d` from repo root); PHPUnit via `docker compose exec app bin/phpunit`.

Spec: [docs/superpowers/specs/2026-08-16-navigation-and-sidebar-enhancements-design.md](../specs/2026-08-16-navigation-and-sidebar-enhancements-design.md)

## Global Constraints

- Previous/Next navigation is computed by adjacent `reportNumber`, skipping gaps (`ORDER BY reportNumber DESC/ASC LIMIT 1` with a strict `<`/`>` comparison) — never assume consecutive numbering.
- The front page's paginated list must exclude the single newest report at every page, not just page 1 — achieved via a uniform offset formula `(page - 1) * perPage + 1`, not special-casing page 1.
- Note pages and the featured front-page report reuse the existing conditional-heading pattern (`{% if not (html|trim starts with '<h1') %}`) established in the prior visual-polish work — never unconditionally add or unconditionally omit the title heading.
- Previous/Next nav links use exact CSS classes `report-nav-prev` and `report-nav-next` (tests and CSS both depend on these exact class names).
- Sidebar folder identity for both `data-path` and `localStorage` keys is the folder's full vault-relative path (e.g. `General/Provisions/Food`), built by the recursive Twig include via a `path` variable analogous to the existing `depth` variable.
- No new composer/npm dependencies, no JS framework, no build step. `sidebar.js` is the only JavaScript file in the project.
- Hiding `Home.md` is a config-only change (`app.vault.hidden_dirs`) — no new code, reusing `VaultFileScanner`'s existing top-level-segment comparison.

---

## File Structure

```
src/Repository/NoteRepository.php               (modified — 3 new methods, 1 modified method)
src/Controller/FrontPageController.php           (modified — featured report + previous link)
src/Controller/NoteController.php                (modified — previous/next report)
templates/front_page/index.html.twig             (modified — featured report block)
templates/note/show.html.twig                    (modified — previous/next nav block)
templates/partials/_sidebar.html.twig            (modified — data-path)
templates/base.html.twig                          (modified — script tag, path:'' )
public/css/site.css                               (modified — featured-report, report-nav)
public/js/sidebar.js                              (new)
config/services.yaml                              (modified — Home.md added to hidden_dirs)
tests/Integration/Repository/NoteRepositoryTest.php  (new)
tests/Functional/Controller/FrontPageControllerTest.php  (modified/rewritten)
tests/Functional/Controller/NoteControllerTest.php   (modified — 4 new test methods)
```

---

### Task 1: NoteRepository — Previous/Next/Newest report queries + pagination offset change

**Files:**
- Modify: `src/Repository/NoteRepository.php`
- Test: `tests/Integration/Repository/NoteRepositoryTest.php` (new)

**Interfaces:**
- Produces: `NoteRepository::findNewestReport(): ?Note`, `findPreviousReport(int $reportNumber): ?Note`, `findNextReport(int $reportNumber): ?Note`, all consumed by `FrontPageController` (Task 2) and `NoteController` (Task 3).
- Modifies: `findReportsPaginated(int $page, int $perPage): Note[]` — offset formula changes from `($page - 1) * $perPage` to `($page - 1) * $perPage + 1`, so the single newest report is always excluded from this method's results regardless of page. Consumed by `FrontPageController` (Task 2), which already calls this method today.

- [ ] **Step 1: Write the failing test**

`tests/Integration/Repository/NoteRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Note;
use App\Repository\NoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NoteRepositoryTest extends KernelTestCase
{
    private NoteRepository $notes;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->notes = $container->get(NoteRepository::class);
        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM App\Entity\Note')->execute();

        // Reports 1, 2, 4, 5, 8 — two gaps (3, and 6-7) to prove
        // Previous/Next skip gaps, and enough rows to prove pagination
        // always excludes the single newest report (8) regardless of page.
        foreach ([1, 2, 4, 5, 8] as $reportNumber) {
            $this->persistReport($reportNumber);
        }
        $this->persistNonReport();

        $this->em->flush();
    }

    public function testFindNewestReportReturnsHighestReportNumber(): void
    {
        self::assertSame(8, $this->notes->findNewestReport()?->getReportNumber());
    }

    public function testFindPreviousReportSkipsGaps(): void
    {
        self::assertSame(5, $this->notes->findPreviousReport(8)?->getReportNumber());
        self::assertSame(4, $this->notes->findPreviousReport(5)?->getReportNumber());
        self::assertSame(2, $this->notes->findPreviousReport(4)?->getReportNumber());
        self::assertSame(1, $this->notes->findPreviousReport(2)?->getReportNumber());
        self::assertNull($this->notes->findPreviousReport(1));
    }

    public function testFindNextReportSkipsGaps(): void
    {
        self::assertSame(2, $this->notes->findNextReport(1)?->getReportNumber());
        self::assertSame(4, $this->notes->findNextReport(2)?->getReportNumber());
        self::assertSame(5, $this->notes->findNextReport(4)?->getReportNumber());
        self::assertSame(8, $this->notes->findNextReport(5)?->getReportNumber());
        self::assertNull($this->notes->findNextReport(8));
    }

    public function testFindReportsPaginatedAlwaysExcludesTheSingleNewestReport(): void
    {
        $page1 = array_map(static fn (Note $n) => $n->getReportNumber(), $this->notes->findReportsPaginated(1, 2));
        $page2 = array_map(static fn (Note $n) => $n->getReportNumber(), $this->notes->findReportsPaginated(2, 2));
        $page3 = array_map(static fn (Note $n) => $n->getReportNumber(), $this->notes->findReportsPaginated(3, 2));

        self::assertSame([5, 4], $page1);
        self::assertSame([2, 1], $page2);
        self::assertSame([], $page3);
    }

    private function persistReport(int $reportNumber): void
    {
        $note = new Note();
        $note->setVaultPath(sprintf('Reports/report-%d.md', $reportNumber));
        $note->setSlug(sprintf('reports/report-%d', $reportNumber));
        $note->setTitle('Report ' . $reportNumber);
        $note->setTopLevelFolder('Reports');
        $note->setHtml('<p>content</p>');
        $note->setReportNumber($reportNumber);
        $this->em->persist($note);
    }

    private function persistNonReport(): void
    {
        $note = new Note();
        $note->setVaultPath('People/Malekith.md');
        $note->setSlug('people/malekith');
        $note->setTitle('Malekith');
        $note->setTopLevelFolder('People');
        $note->setHtml('<p>content</p>');
        $this->em->persist($note);
    }

    protected function tearDown(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Note')->execute();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app bin/phpunit tests/Integration/Repository/NoteRepositoryTest.php`
Expected: FAIL — `findNewestReport()`/`findPreviousReport()`/`findNextReport()` don't exist yet, and `testFindReportsPaginatedAlwaysExcludesTheSingleNewestReport` fails against the current (unmodified) offset formula, which would return `[8, 5]` for page 1, not `[5, 4]`.

- [ ] **Step 3: Add the new methods and modify the pagination offset**

Modify `src/Repository/NoteRepository.php`, add these three methods (anywhere inside the class, e.g. after `countReports()`):
```php
    public function findNewestReport(): ?Note
    {
        return $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->orderBy('n.reportNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findPreviousReport(int $reportNumber): ?Note
    {
        return $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->andWhere('n.reportNumber < :reportNumber')
            ->setParameter('reportNumber', $reportNumber)
            ->orderBy('n.reportNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findNextReport(int $reportNumber): ?Note
    {
        return $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->andWhere('n.reportNumber > :reportNumber')
            ->setParameter('reportNumber', $reportNumber)
            ->orderBy('n.reportNumber', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
```

And change `findReportsPaginated()`'s existing body from:
```php
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
```
to:
```php
    /**
     * Always excludes the single newest report (see findNewestReport()),
     * regardless of page — the caller renders that one separately as the
     * front page's featured report.
     */
    public function findReportsPaginated(int $page, int $perPage): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.reportNumber IS NOT NULL')
            ->orderBy('n.reportNumber', 'DESC')
            ->setFirstResult(($page - 1) * $perPage + 1)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Integration/Repository/NoteRepositoryTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the full suite to confirm no regressions in existing callers**

Run: `docker compose exec app bin/phpunit`
Expected: `FrontPageControllerTest`'s existing test will likely now FAIL or behave differently, since `findReportsPaginated()`'s meaning changed — that's expected and is fixed in Task 2, not here. Confirm no *other* unrelated test regresses.

- [ ] **Step 6: Commit**

```bash
git add src/Repository/NoteRepository.php tests/Integration/Repository/NoteRepositoryTest.php
git commit -m "Add Previous/Next/Newest report queries; exclude newest from paginated list"
```

---

### Task 2: Front page features the newest report in full

**Files:**
- Modify: `src/Controller/FrontPageController.php`
- Modify: `templates/front_page/index.html.twig`
- Modify: `public/css/site.css`
- Modify: `tests/Functional/Controller/FrontPageControllerTest.php`

**Interfaces:**
- Consumes: `NoteRepository::findNewestReport()`, `findPreviousReport()`, `findReportsPaginated()` (Task 1).
- Produces: `featuredReport` and `featuredPreviousReport` template variables, consumed only by `front_page/index.html.twig` in this task.

- [ ] **Step 1: Update FrontPageController**

Modify `src/Controller/FrontPageController.php`'s `__invoke` method from:
```php
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
```
to:
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

- [ ] **Step 2: Update the template**

Modify `templates/front_page/index.html.twig` from:
```twig
{% extends 'base.html.twig' %}

{% block title %}Session Reports — RPG Notes{% endblock %}

{% block body %}
    <h1>Session Reports</h1>
    <ul class="report-list">
```
to:
```twig
{% extends 'base.html.twig' %}

{% block title %}Session Reports — RPG Notes{% endblock %}

{% block body %}
    {% if featuredReport %}
        <article class="featured-report">
            {% if not (featuredReport.html|trim starts with '<h1') %}
                <h1>{{ featuredReport.title }}</h1>
            {% endif %}
            {{ featuredReport.html|raw }}
            {% if featuredPreviousReport %}
                <nav class="report-nav">
                    <a href="/notes/{{ featuredPreviousReport.slug }}" class="report-nav-prev">← Previous session</a>
                </nav>
            {% endif %}
        </article>
        <h2 class="older-sessions-heading">Older Sessions</h2>
    {% else %}
        <h1>Session Reports</h1>
    {% endif %}
    <ul class="report-list">
```
(the rest of the file — the `{% for report in reports %}` loop and the `<nav class="pagination">` block — is unchanged).

- [ ] **Step 3: Add CSS for the featured report and nav links**

Append to `public/css/site.css`:
```css
.featured-report {
    margin-bottom: 32px;
    padding-bottom: 24px;
    border-bottom: 2px solid var(--color-border);
}

.older-sessions-heading {
    font-size: 1.1em;
    margin-top: 0;
}

.report-nav {
    display: flex;
    justify-content: space-between;
    margin-top: 32px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border);
}

.report-nav-prev,
.report-nav-next {
    font-weight: bold;
}

.report-nav-next {
    margin-left: auto;
}
```

- [ ] **Step 4: Replace the functional test**

Replace the full contents of `tests/Functional/Controller/FrontPageControllerTest.php` with:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FrontPageControllerTest extends WebTestCase
{
    public function testPage1ShowsFeaturedNewestReportInFullAndExcludesItFromTheListBelow(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $report1 = $this->makeReport($em, 1, 'report-1', '<p>First session content.</p>');
        $report2 = $this->makeReport($em, 2, 'report-2', '<p>Second session content.</p>');
        $report3 = $this->makeReport($em, 3, 'report-3', '<p>Third session content.</p>');

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Third session content.', $content);
        self::assertStringNotContainsString('href="/notes/report-3"', $content);
        self::assertStringContainsString('href="/notes/report-2"', $content);
        self::assertStringContainsString('href="/notes/report-1"', $content);
        self::assertTrue(
            strpos($content, 'href="/notes/report-2"') < strpos($content, 'href="/notes/report-1"')
        );

        foreach ([$report1, $report2, $report3] as $note) {
            $em->remove($note);
        }
        $em->flush();
    }

    public function testFeaturedReportLinksToThePreviousSession(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $report1 = $this->makeReport($em, 1, 'report-1', '<p>First.</p>');
        $report2 = $this->makeReport($em, 2, 'report-2', '<p>Second.</p>');

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('href="/notes/report-1" class="report-nav-prev"', $content);

        foreach ([$report1, $report2] as $note) {
            $em->remove($note);
        }
        $em->flush();
    }

    public function testExcludesNonReportNotes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $report = $this->makeReport($em, 1, 'report-1', '<p>content</p>');
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
        self::assertStringNotContainsString('Summary', (string) $client->getResponse()->getContent());

        foreach ([$report, $summary] as $note) {
            $em->remove($note);
        }
        $em->flush();
    }

    private function makeReport(EntityManagerInterface $em, int $number, string $slug, string $html): Note
    {
        $note = new Note();
        $note->setVaultPath('Reports/report-' . $number . '.md');
        $note->setSlug($slug);
        $note->setTitle('Report ' . $number);
        $note->setTopLevelFolder('Reports');
        $note->setHtml($html);
        $note->setReportNumber($number);
        $em->persist($note);
        $em->flush();

        return $note;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/FrontPageControllerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Verify visually against the real synced vault**

Run: `curl -s http://localhost:8091/ | grep -c 'class="featured-report"'`
Expected: `1`

Run: `curl -s http://localhost:8091/ | grep -c 'class="report-nav-prev"'`
Expected: `1`

- [ ] **Step 7: Run the full suite**

Run: `docker compose exec app bin/phpunit`
Expected: all tests pass.

- [ ] **Step 8: Commit**

```bash
git add src/Controller/FrontPageController.php templates/front_page/index.html.twig public/css/site.css tests/Functional/Controller/FrontPageControllerTest.php
git commit -m "Feature the newest session report in full on the front page"
```

---

### Task 3: Previous/Next navigation on report pages

**Files:**
- Modify: `src/Controller/NoteController.php`
- Modify: `templates/note/show.html.twig`
- Modify: `tests/Functional/Controller/NoteControllerTest.php`

**Interfaces:**
- Consumes: `NoteRepository::findPreviousReport()`, `findNextReport()` (Task 1).
- Produces: `previousReport`/`nextReport` template variables, consumed only by `note/show.html.twig`.

- [ ] **Step 1: Write the failing tests**

Append these four methods to `tests/Functional/Controller/NoteControllerTest.php` (inside the class, alongside the existing three test methods), and add a private helper:
```php
    public function testReportNoteShowsPreviousAndNextLinks(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $report1 = $this->makeReport($em, 1, 'reports/report-1');
        $report2 = $this->makeReport($em, 2, 'reports/report-2');
        $report3 = $this->makeReport($em, 3, 'reports/report-3');

        $client->request('GET', '/notes/reports/report-2');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/notes/reports/report-1" class="report-nav-prev"', $content);
        self::assertStringContainsString('href="/notes/reports/report-3" class="report-nav-next"', $content);

        foreach ([$report1, $report2, $report3] as $note) {
            $em->remove($note);
        }
        $em->flush();
    }

    public function testNewestReportOmitsNextLink(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $report1 = $this->makeReport($em, 1, 'reports/report-1');
        $report2 = $this->makeReport($em, 2, 'reports/report-2');

        $client->request('GET', '/notes/reports/report-2');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('report-nav-prev', $content);
        self::assertStringNotContainsString('report-nav-next', $content);

        foreach ([$report1, $report2] as $note) {
            $em->remove($note);
        }
        $em->flush();
    }

    public function testOldestReportOmitsPreviousLink(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $report1 = $this->makeReport($em, 1, 'reports/report-1');
        $report2 = $this->makeReport($em, 2, 'reports/report-2');

        $client->request('GET', '/notes/reports/report-1');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('report-nav-prev', $content);
        self::assertStringContainsString('report-nav-next', $content);

        foreach ([$report1, $report2] as $note) {
            $em->remove($note);
        }
        $em->flush();
    }

    public function testNonReportNoteShowsNoNavigationLinks(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $note = new Note();
        $note->setVaultPath('Locations/Millbrook.md');
        $note->setSlug('locations/millbrook');
        $note->setTitle('Millbrook');
        $note->setTopLevelFolder('Locations');
        $note->setHtml('<p>A quiet crossroads village.</p>');
        $em->persist($note);
        $em->flush();

        $client->request('GET', '/notes/locations/millbrook');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('report-nav-prev', $content);
        self::assertStringNotContainsString('report-nav-next', $content);

        $em->remove($note);
        $em->flush();
    }

    private function makeReport(EntityManagerInterface $em, int $number, string $slug): Note
    {
        $note = new Note();
        $note->setVaultPath('Reports/report-' . $number . '.md');
        $note->setSlug($slug);
        $note->setTitle('Report ' . $number);
        $note->setTopLevelFolder('Reports');
        $note->setHtml('<p>content</p>');
        $note->setReportNumber($number);
        $em->persist($note);
        $em->flush();

        return $note;
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/NoteControllerTest.php`
Expected: FAIL — the template has no Previous/Next markup yet, so the `report-nav-prev`/`report-nav-next` assertions fail.

- [ ] **Step 3: Update NoteController**

Modify `src/Controller/NoteController.php`'s `__invoke` method from:
```php
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
```
to:
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

- [ ] **Step 4: Update the template**

Modify `templates/note/show.html.twig` from:
```twig
{% extends 'base.html.twig' %}

{% block title %}{{ note.title }} — RPG Notes{% endblock %}

{% block body %}
    <article>
        {% if not (note.html|trim starts with '<h1') %}
            <h1>{{ note.title }}</h1>
        {% endif %}
        {{ note.html|raw }}
    </article>
{% endblock %}
```
to:
```twig
{% extends 'base.html.twig' %}

{% block title %}{{ note.title }} — RPG Notes{% endblock %}

{% block body %}
    <article>
        {% if not (note.html|trim starts with '<h1') %}
            <h1>{{ note.title }}</h1>
        {% endif %}
        {{ note.html|raw }}
    </article>
    {% if note.reportNumber is not null and (previousReport or nextReport) %}
        <nav class="report-nav">
            {% if previousReport %}
                <a href="/notes/{{ previousReport.slug }}" class="report-nav-prev">← Previous session</a>
            {% endif %}
            {% if nextReport %}
                <a href="/notes/{{ nextReport.slug }}" class="report-nav-next">Next session →</a>
            {% endif %}
        </nav>
    {% endif %}
{% endblock %}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/NoteControllerTest.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Verify visually against the real synced vault**

Run: `curl -s http://localhost:8091/notes/reports/41-50/report-41-20-2-1367-matka-brokenstonen-laaksoon | grep -c 'report-nav-'`
Expected: a number greater than `0` (this report has both an older and newer report in the real vault).

- [ ] **Step 7: Run the full suite**

Run: `docker compose exec app bin/phpunit`
Expected: all tests pass.

- [ ] **Step 8: Commit**

```bash
git add src/Controller/NoteController.php templates/note/show.html.twig tests/Functional/Controller/NoteControllerTest.php
git commit -m "Add Previous/Next session navigation to report pages"
```

---

### Task 4: Hide the Home note

**Files:**
- Modify: `config/services.yaml`

**Interfaces:**
- None — config-only change to an existing parameter already consumed by `VaultFileScanner`/`NoteIndexer` (Phase 1).

- [ ] **Step 1: Add Home.md to the hidden dirs list**

Modify `config/services.yaml`'s parameters block from:
```yaml
parameters:
    app.vault.excluded_dirs: ['.obsidian', 'docs']
    app.vault.hidden_dirs: ['A - GM']
```
to:
```yaml
parameters:
    app.vault.excluded_dirs: ['.obsidian', 'docs']
    app.vault.hidden_dirs: ['A - GM', 'Home.md']
```

- [ ] **Step 2: Re-sync and verify against the real vault**

Run: `docker compose exec app bin/console app:sync`
Expected: output confirms notes were indexed (e.g. `Indexed 427 notes, removed 1 stale notes.` — one fewer than before, since `Home.md` is now excluded).

Run: `curl -s -o /dev/null -w '%{http_code}' http://localhost:8091/notes/home`
Expected: `404`

Run: `curl -s http://localhost:8091/ | grep -c 'href="/notes/home"'`
Expected: `0` (not linked from anywhere, including the sidebar).

- [ ] **Step 3: Commit**

```bash
git add config/services.yaml
git commit -m "Hide the Home note using the existing hidden-folder mechanism"
```

---

### Task 5: Sidebar state persistence via localStorage

**Files:**
- Create: `public/js/sidebar.js`
- Modify: `templates/partials/_sidebar.html.twig`
- Modify: `templates/base.html.twig`

**Interfaces:**
- Produces: `data-path` attribute on every sidebar `<details>` element (full vault-relative folder path), consumed by `public/js/sidebar.js`.

- [ ] **Step 1: Add path tracking to the sidebar template**

Modify `templates/partials/_sidebar.html.twig` from:
```twig
<ul>
    {% for folder in node.folders %}
        <li>
            <details{% if depth == 0 %} open{% endif %}>
                <summary>{{ folder.name }}</summary>
                {% include 'partials/_sidebar.html.twig' with { node: folder, depth: depth + 1 } %}
            </details>
        </li>
    {% endfor %}
    {% for note in node.notes %}
        <li><a href="/notes/{{ note.slug }}">{{ note.title }}</a></li>
    {% endfor %}
</ul>
```
to:
```twig
<ul>
    {% for folder in node.folders %}
        {% set folderPath = path is not empty ? path ~ '/' ~ folder.name : folder.name %}
        <li>
            <details{% if depth == 0 %} open{% endif %} data-path="{{ folderPath }}">
                <summary>{{ folder.name }}</summary>
                {% include 'partials/_sidebar.html.twig' with { node: folder, depth: depth + 1, path: folderPath } %}
            </details>
        </li>
    {% endfor %}
    {% for note in node.notes %}
        <li><a href="/notes/{{ note.slug }}">{{ note.title }}</a></li>
    {% endfor %}
</ul>
```

- [ ] **Step 2: Pass the initial empty path from base.html.twig**

Modify `templates/base.html.twig`'s sidebar include from:
```twig
            {% include 'partials/_sidebar.html.twig' with { node: sidebar, depth: 0 } %}
```
to:
```twig
            {% include 'partials/_sidebar.html.twig' with { node: sidebar, depth: 0, path: '' } %}
```

- [ ] **Step 3: Write the JavaScript**

`public/js/sidebar.js`:
```javascript
(function () {
    var STORAGE_KEY = 'sidebar-state';

    function loadState() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : {};
        } catch (e) {
            return {};
        }
    }

    function saveState(state) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {
            // localStorage unavailable (private browsing, quota, etc.) — degrade silently.
        }
    }

    var state = loadState();
    var detailsElements = document.querySelectorAll('.sidebar details[data-path]');

    detailsElements.forEach(function (details) {
        var path = details.getAttribute('data-path');

        if (Object.prototype.hasOwnProperty.call(state, path)) {
            details.open = state[path];
        }

        details.addEventListener('toggle', function () {
            state[path] = details.open;
            saveState(state);
        });
    });
})();
```

- [ ] **Step 4: Load the script from the base layout**

Modify `templates/base.html.twig` from:
```twig
        <main class="content">
            {% block body %}{% endblock %}
        </main>
    </div>
</body>
</html>
```
to:
```twig
        <main class="content">
            {% block body %}{% endblock %}
        </main>
    </div>
    <script defer src="/js/sidebar.js"></script>
</body>
</html>
```

- [ ] **Step 5: Run the existing sidebar test to confirm no PHP-side regression**

Run: `docker compose exec app bin/phpunit tests/Integration/Service/Sidebar/SidebarBuilderTest.php`
Expected: PASS (this test exercises PHP tree-building, unaffected by template/JS changes).

- [ ] **Step 6: Verify visually against the real synced vault**

Run: `curl -s -o /dev/null -w '%{http_code}' http://localhost:8091/js/sidebar.js`
Expected: `200`

Run: `curl -s http://localhost:8091/ | grep -o 'data-path="[^"]*"' | head -5`
Expected: several `data-path="..."` attributes with real folder names (e.g. `data-path="People"`, `data-path="Locations"`).

Manual browser check (describe in the commit/report, cannot be scripted): open `http://localhost:8091/`, expand a nested folder (e.g. `Locations` → `Settlements`), reload the page — the folder should still be expanded. Collapse a top-level folder that starts open by default (e.g. `People`), reload — it should stay collapsed. A folder never touched should still show today's default (top-level open, nested closed).

- [ ] **Step 7: Run the full suite**

Run: `docker compose exec app bin/phpunit`
Expected: all tests pass (no PHP-side test exercises JS, so this just confirms the template changes didn't break anything server-side).

- [ ] **Step 8: Commit**

```bash
git add public/js/sidebar.js templates/partials/_sidebar.html.twig templates/base.html.twig
git commit -m "Persist sidebar expand/collapse state via localStorage"
```

---

## Self-Review Notes

- **Spec coverage:** sidebar localStorage persistence (Task 5) · hide Home.md via config (Task 4) · navbar Home item (already satisfied, no task needed, documented in spec) · front page features newest report in full with list exclusion (Task 2) · Previous/Next navigation on report pages and the featured front-page report (Tasks 2 & 3) — every spec goal has a task or an explicit no-op note.
- **Placeholder scan:** no TBD/TODO; every step has literal code, exact commands, or a clearly-described manual browser check (JS behavior has no test framework in this project, consistent with how CSS/visual changes were verified in the prior plan).
- **Type consistency:** `findNewestReport()`/`findPreviousReport()`/`findNextReport()` signatures are defined once in Task 1 and consumed with identical names/types in Tasks 2 and 3. The `path`/`data-path` naming is introduced in Task 5 and doesn't conflict with the `depth` variable from the prior visual-polish plan (both threaded independently through the same recursive include). `report-nav-prev`/`report-nav-next` CSS class names are defined in Task 2's CSS and reused verbatim (not redefined) in Task 3's template and tests.
