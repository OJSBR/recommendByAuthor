# Test cases — recommendByAuthor

Three suites, with different reaches. **Re-run them on every OJS upgrade** and on every change
to the plugin: it sits on hooks and repository APIs that PKP has already changed between minor
releases.

| Suite | What it covers | Last run |
|---|---|---|
| `tests/regression.php` | the rules, the index, the store and the whole path, against a real database | **62 cases, 62 passed** |
| `tests/AuthorKeyTest.php` (PHPUnit) | the identity rules on their own, no database | **passed** — 36 tests / 54 assertions together with the similarity plugin's |
| `cypress/tests/functional/` | enabling, settings and the article page, in a browser | **7 tests, 7 passed** |

All three were run against OJS 3.5.0.3 on PHP 8.2 before this was published.

## How to run

```bash
# 1. Regression: needs only a working install with one journal.
php plugins/generic/recommendByAuthor/tests/regression.php

# 2. Unit: needs the dev dependencies (composer install) and the pkp-lib test
#    tree. The PKP configuration picks plugin tests up on its own.
cd lib/pkp/tests && ../lib/vendor/bin/phpunit -c phpunit.xml --testsuite ApplicationPlugins

# 3. Functional: needs Node and Cypress. Copy the spec under cypress/tests/ of
#    the installation, or point --spec at it.
npx cypress run --spec 'cypress/tests/**/RecommendByAuthor.cy.js'
```

The Cypress spec assumes the PKP test data (`publicknowledge`, `admin`/`admin`).
Any other installation can run the very same spec by passing its own values:

```json
{ "contextPath": "myjournal", "adminUser": "…", "adminPassword": "…", "articleId": 42 }
```

in `cypress.env.json`. It solves the Altcha proof of work when the site has
`captcha_on_login` turned on, sets a session cookie so an edge cache cannot
answer for the application, and drives the grid by element name rather than by
label, so the language of the interface does not matter.

Run them as the account that owns the files, never as root.

> **Test installations only.** The regression suite creates and deletes submissions. Everything
> it creates is titled `[RBA-REGRESSION]` and removed at the end; `--keep` leaves it behind for
> inspection, and the next run cleans it up. It writes `tests/results.json` and exits non-zero
> if any case fails.

## What each block guards

### A — Author keys (20 cases, no database)

The rules that decide whether two records are the same person. These are the whole basis of the
plugin's correctness: too strict and it finds nothing, too loose and it invents co-authorship.

- **A01–A10** — accents, case, stray and double spaces, initials punctuation, tabs. A04 and A05
  are real data from a production journal: `"Ana Carolina␣␣Menezes"`, which the original plugin misses
  because it compares strings exactly.
- **A11** — *a nameless author has no key.* This is the bug the plugin refuses to reproduce: the
  original calls `filterByName('', '')`, which matches every other nameless author in the
  database. On one journal that was 222 authors across 97 articles, each getting ~3,285
  irrelevant recommendations and ~100 s per page view.
- **A12–A13** — the same person folds together; similar-but-different people do not.
- **A14** — a name longer than the column is hashed, not truncated, so two long names cannot
  collide by having the same first 160 characters.
- **A15–A20** — ORCID in every form it is stored in, and nothing else accepted as one.

### B — Author index (16 cases, against the database)

- **B01–B02** — published submissions are indexed under the normalised key.
- **B03–B04** — ORCID adds a key without replacing the name key, and the setting turns it off.
  This matters: matching by ORCID must only ever *add* articles.
- **B05** — a nameless author produces no index row at all.
- **B06–B07** — a name recorded in several locales: identical spellings fold to one key, genuinely
  different ones yield a key each.
- **B08** — reindexing replaces instead of duplicating.
- **B09** — an unpublished submission drops out of the index, which is what removes it from
  everybody else's recommendations.
- **B10–B12** — the co-authorship query: it finds the right articles, never the article itself,
  and counts how many authors are shared.
- **B13** — *ORCID finds the author who changed surname.* The feature the original plugin's own
  comments asked for.
- **B14** — a nameless author recommends nothing.
- **B15** — the batched query agrees with the single one, case by case. They are different SQL and
  the scheduled task depends on the batched one.
- **B16** — `neighboursOf`, which decides who gets re-queued when an article is published.

### C — Store (15 cases)

- **C01–C03** — enrolment: once per submission, only for the journals asked about.
- **C04–C05** — the index phase and its queue. Guards the ordering that stops half-built lists
  being stored as finished.
- **C06–C09** — refresh writes an ordered list; the journal's ranking decides the order; shared
  authors break ties.
- **C10–C11** — the storage cap and paging.
- **C12** — invalidation clears `computed_at` **and** bumps the version, which is what makes the
  already-rendered HTML unreachable. Without the version bump a reader would keep seeing a stale
  section until the HTML cache expired.
- **C13–C15** — the queue order (never-computed first), the batch size, and the enrolment cap.

### D — End to end (6 cases)

- **D01** — an article published later shows up in the recommendations of its co-author.
- **D02** — the `Publication::publish` hook queues the neighbours, not just the new article.
- **D03** — the dirty-spacing article really is recommended, end to end.
- **D04** — an article whose only author is nameless recommends nothing.
- **D05–D06** — deleting a submission removes its rows through the foreign keys, and it stops
  being recommended to others. This is what makes uninstalling safe.

### E — Guards (5 cases)

- **E01–E02** — unknown submissions and empty batches are harmless.
- **E03** — the defaults are the safe ones, above all `computeOnDemand = 0`.
- **E04** — `enabledContextIds()` reads the settings rather than the request, which is the only
  thing that is true inside the scheduled task, where there is no journal in the request.
- **E05** — no orphan rows in any of the three tables, in either direction.

## What is not covered here

- **Load.** The performance figures in the README were measured on a production journal, not
  asserted by a test. A regression in speed would not fail this suite.
- **The rendered HTML** is checked by the Cypress spec and was verified by hand in a browser; the
  PHP suites stop at the stored list.
- **Multi-journal isolation** needs a second journal, which the suite does not create. The index
  and the queries carry `context_id` and the store filters by it; a case for it belongs in an
  install that has two journals.
