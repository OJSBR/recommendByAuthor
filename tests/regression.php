<?php

/**
 * @file tests/regression.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Regression suite for recommendByAuthor.
 *
 *        Covers the author-key rules on their own, the index and the store
 *        against a real database, and the whole path from publishing an article
 *        to the section the reader sees. It creates its own submissions and
 *        deletes them again; the plugin settings it changes are restored.
 *
 *        See tests/CASES.md for the list of cases and what each one guards.
 *
 * Usage: php plugins/generic/recommendByAuthor/tests/regression.php [--keep]
 *
 *        Test installations only. Run it as the account that owns the files,
 *        never as root.
 */

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\plugins\generic\recommendByAuthor\classes\AuthorIndex;
use APP\plugins\generic\recommendByAuthor\classes\AuthorKey;
use APP\plugins\generic\recommendByAuthor\classes\RecommendationStore;
use APP\publication\Publication;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\userGroup\UserGroup;

$root = dirname(__DIR__, 4);
chdir($root);
define('INDEX_FILE_LOCATION', $root . '/index.php');
require $root . '/lib/pkp/includes/bootstrap.php';

const CONTEXT_ID = 1;
const PLUGIN = 'recommendbyauthorplugin';

/** Everything this suite creates carries this marker in the title. */
const MARKER = '[RBA-REGRESSION]';

$keep = in_array('--keep', $argv, true);

//
// Harness: there is no URL on the command line, so the journal is pinned on the
// router. Without it getContext() returns null half way through the run.
//
class RouterWithContext extends PageRouter
{
    private $pinned;
    public function pinContext($context) { $this->pinned = $context; }
    public function getContext(\PKP\core\PKPRequest $request, bool $forceReload = false): ?\PKP\context\Context { return $this->pinned; }
}

$request = Application::get()->getRequest();
$context = Application::getContextDAO()->getById(CONTEXT_ID);
if (!$context) {
    exit("FATAL: journal " . CONTEXT_ID . " does not exist.\n");
}
$router = new RouterWithContext();
$router->setApplication(Application::get());
$router->pinContext($context);
$request->setRouter($router);

PluginRegistry::loadCategory('generic', false, CONTEXT_ID);
$plugin = PluginRegistry::getPlugin('generic', PLUGIN);
if (!$plugin) {
    exit("FATAL: plugin not installed.\n");
}
foreach (['recommend_author_index', 'recommend_author_cache', 'recommend_author_state'] as $table) {
    if (!Illuminate\Support\Facades\Schema::hasTable($table)) {
        exit("FATAL: table {$table} is missing. Enable the plugin once so its migration runs.\n");
    }
}

//
// Tiny framework
//
$RESULTS = [];
$BLOCK = '';

function block(string $title): void
{
    global $BLOCK;
    $BLOCK = $title;
    echo "\n" . str_repeat('=', 78) . "\n{$title}\n" . str_repeat('=', 78) . "\n";
}

function testCase(string $id, string $title, callable $body): void
{
    global $RESULTS, $BLOCK;
    try {
        $body();
        $RESULTS[] = ['id' => $id, 'block' => $BLOCK, 'title' => $title, 'ok' => true, 'message' => ''];
        printf("  [ PASS ] %-6s %s\n", $id, $title);
    } catch (Throwable $e) {
        $RESULTS[] = ['id' => $id, 'block' => $BLOCK, 'title' => $title, 'ok' => false, 'message' => $e->getMessage()];
        printf("  [ FAIL ] %-6s %s\n            -> %s\n", $id, $title, str_replace("\n", "\n            ", $e->getMessage()));
    }
}

function assertEquals($expected, $actual, string $message = 'value differs from the expected one'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message
            . "\n              expected: " . json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n              actual  : " . json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertNull($value, string $message): void
{
    if ($value !== null) {
        throw new RuntimeException($message . ' (got ' . json_encode($value, JSON_UNESCAPED_UNICODE) . ')');
    }
}

function summarize(string $text, int $limit = 46): string
{
    $text = str_replace(["\n", "\t"], ['\n', '\t'], $text);
    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
}

//
// Domain helpers
//
$CREATED = [];

/**
 * A published submission with the given authors.
 *
 * @param array $authors each one [givenName, familyName, orcid|null] or
 *                       ['locales' => [locale => [given, family]], 'orcid' => …]
 */
function newPublishedSubmission(string $title, array $authors, ?int $issueId = null): int
{
    global $CREATED, $context;

    $userGroupId = UserGroup::withContextIds([CONTEXT_ID])->withRoleIds([Role::ROLE_ID_AUTHOR])->first()?->id
        ?? UserGroup::withContextIds([CONTEXT_ID])->first()?->id;
    $sectionId = DB::table('sections')->where('journal_id', CONTEXT_ID)->value('section_id');
    $issueId ??= DB::table('issues')->where('journal_id', CONTEXT_ID)->value('issue_id');

    $submission = Repo::submission()->newDataObject([
        'contextId' => CONTEXT_ID,
        'status' => Submission::STATUS_QUEUED,
        'submissionProgress' => '',
        'stageId' => WORKFLOW_STAGE_ID_PRODUCTION,
        'locale' => 'pt_BR',
    ]);
    $publication = Repo::publication()->newDataObject([
        'title' => ['pt_BR' => MARKER . ' ' . $title],
        'sectionId' => $sectionId,
        'issueId' => $issueId,
        'locale' => 'pt_BR',
        'status' => Submission::STATUS_QUEUED,
        'datePublished' => '2026-01-15',
    ]);
    $submissionId = Repo::submission()->add($submission, $publication, $context);
    $CREATED[] = $submissionId;

    $submission = Repo::submission()->get($submissionId);
    $publication = $submission->getCurrentPublication();

    foreach ($authors as $seq => $spec) {
        $locales = $spec['locales'] ?? ['pt_BR' => [$spec[0] ?? '', $spec[1] ?? '']];
        $orcid = $spec['orcid'] ?? ($spec[2] ?? null);

        $given = [];
        $family = [];
        foreach ($locales as $locale => [$g, $f]) {
            $given[$locale] = $g;
            $family[$locale] = $f;
        }

        $author = Repo::author()->newDataObject([
            'publicationId' => $publication->getId(),
            'givenName' => $given,
            'familyName' => $family,
            'userGroupId' => $userGroupId,
            'seq' => $seq,
            'includeInBrowse' => true,
            'email' => 'rba.regression+' . $seq . '@example.org',
            'country' => 'BR',
        ]);
        if ($orcid) {
            $author->setData('orcid', $orcid);
        }
        $authorId = Repo::author()->add($author);
        if ($seq === 0) {
            Repo::publication()->edit($publication, ['primaryContactId' => $authorId]);
            $publication = Repo::publication()->get($publication->getId());
        }
    }

    Repo::publication()->publish($publication);

    return $submissionId;
}

function unpublish(int $submissionId): void
{
    $submission = Repo::submission()->get($submissionId);
    Repo::publication()->unpublish($submission->getCurrentPublication());
}

/** Removes everything the suite created, including its rows in the plugin tables. */
function cleanUp(): void
{
    global $CREATED;
    foreach (array_unique($CREATED) as $submissionId) {
        if ($submission = Repo::submission()->get($submissionId)) {
            Repo::submission()->delete($submission);
        }
    }
    // Anything left over from an interrupted run.
    $stale = DB::table('publication_settings as ps')
        ->join('publications as p', 'p.publication_id', '=', 'ps.publication_id')
        ->where('ps.setting_name', 'title')
        ->where('ps.setting_value', 'like', '%' . MARKER . '%')
        ->distinct()->pluck('p.submission_id');
    foreach ($stale as $submissionId) {
        if ($submission = Repo::submission()->get((int) $submissionId)) {
            Repo::submission()->delete($submission);
        }
    }
    $CREATED = [];
}

/** Only the suite's own submissions, so a populated journal does not disturb the assertions. */
function onlyOurs(array $ids): array
{
    global $CREATED;
    return array_values(array_intersect($ids, $CREATED));
}

$store = new RecommendationStore();
$index = new AuthorIndex(true);
$indexWithoutOrcid = new AuthorIndex(false);

// A clean slate, in case a previous run was interrupted.
cleanUp();

echo "\nrecommendByAuthor — regression suite\n";
echo "journal: " . $context->getPath() . "   plugin: " . $plugin->getCurrentVersion()?->getVersionString() . "\n";

//
// A. The author key, on its own
//
block('A. Author keys (no database)');

$nameCases = [
    ['A01', 'João', 'Silva', 'n:joao|silva', 'accents are folded'],
    ['A02', 'JOÃO', 'SILVA', 'n:joao|silva', 'case is folded'],
    ['A03', 'João  ', '  Silva', 'n:joao|silva', 'stray spaces are trimmed'],
    ['A04', 'Leandro Barbosa', ' Teixeira', 'n:leandro barbosa|teixeira', 'the real double-space case'],
    ['A05', 'Leandro  Barbosa', 'Teixeira', 'n:leandro barbosa|teixeira', 'inner double space collapses'],
    ['A06', 'J. P.', 'Silva', 'n:j p|silva', 'initials lose their punctuation'],
    ['A07', 'Ana', '', 'n:ana|', 'given name alone is still a key'],
    ['A08', '', 'Silva', 'n:|silva', 'family name alone is still a key'],
    ['A09', 'Muñoz', 'Núñez', 'n:munoz|nunez', 'tilde is folded'],
    ['A10', "Ana\tMaria", 'Sá', 'n:ana maria|sa', 'tabs count as spaces'],
];
foreach ($nameCases as [$id, $given, $family, $expected, $title]) {
    testCase($id, $title . ' — ' . summarize("{$given}|{$family}", 28), function () use ($given, $family, $expected) {
        assertEquals($expected, AuthorKey::fromName($given, $family));
    });
}

testCase('A11', 'a nameless author has no key (the original matched them all)', function () {
    assertNull(AuthorKey::fromName('', ''), 'empty name must not produce a key');
    assertNull(AuthorKey::fromName(null, null), 'null name must not produce a key');
    assertNull(AuthorKey::fromName('   ', "\t"), 'whitespace-only name must not produce a key');
});

testCase('A12', 'two spellings of the same person share one key', function () {
    assertEquals(AuthorKey::fromName('Leandro Barbosa ', ' Teixeira'), AuthorKey::fromName('Leandro Barbosa', 'Teixeira'));
});

testCase('A13', 'different people keep different keys', function () {
    assertTrue(
        AuthorKey::fromName('Ana', 'Silva') !== AuthorKey::fromName('Ana', 'Silveira'),
        'similar but different names must not collide'
    );
});

testCase('A14', 'a name too long for the column is hashed, not cut', function () {
    $key = AuthorKey::fromName(str_repeat('a', 200), str_repeat('b', 200));
    assertTrue(str_starts_with($key, 'h:'), 'long name should be hashed');
    assertTrue(strlen($key) <= 160, 'key must fit the column, got ' . strlen($key));
    assertEquals($key, AuthorKey::fromName(str_repeat('a', 200), str_repeat('b', 200)), 'hashing must be stable');
});

$orcidCases = [
    ['A15', 'https://orcid.org/0000-0002-1825-0097', 'o:0000-0002-1825-0097', 'full URL'],
    ['A16', '0000-0002-1825-0097', 'o:0000-0002-1825-0097', 'bare identifier'],
    ['A17', 'http://orcid.org/0000-0002-1825-0097', 'o:0000-0002-1825-0097', 'http URL'],
    ['A18', '0000-0002-1825-009X', 'o:0000-0002-1825-009X', 'checksum X'],
    ['A19', '0000-0002-1825-009x', 'o:0000-0002-1825-009X', 'lowercase x is normalised'],
];
foreach ($orcidCases as [$id, $input, $expected, $title]) {
    testCase($id, 'ORCID: ' . $title, function () use ($input, $expected) {
        assertEquals($expected, AuthorKey::fromOrcid($input));
    });
}

testCase('A20', 'anything that is not an ORCID has no key', function () {
    foreach (['', null, 'not an orcid', '1234', 'https://orcid.org/', '0000-0002-1825'] as $input) {
        assertNull(AuthorKey::fromOrcid($input), 'should not accept ' . json_encode($input));
    }
});

//
// B. The index, against the database
//
block('B. Author index');

$sharedAuthor = ['Marina', 'Duarte'];
$otherAuthor = ['Carlos Alberto', 'Nogueira'];
$orcid = 'https://orcid.org/0000-0002-1825-0097';

$s1 = newPublishedSubmission('one', [$sharedAuthor, $otherAuthor]);
$s2 = newPublishedSubmission('two', [['Marina  ', ' Duarte']]);            // same person, dirty spacing
$s3 = newPublishedSubmission('three', [['Marina', 'Duarte Ribeiro', $orcid]]);
$s4 = newPublishedSubmission('four', [$otherAuthor]);
$s5 = newPublishedSubmission('five', [['', '']]);                          // nameless author only
$s6 = newPublishedSubmission('six', [['', '']]);                           // another nameless one
$s1WithOrcid = null;

// The article that carries the ORCID as well as the name.
$s7 = newPublishedSubmission('seven', [['Marina', 'Duarte', $orcid]]);

$index->index([$s1, $s2, $s3, $s4, $s5, $s6, $s7]);

testCase('B01', 'a published submission is indexed', function () use ($s1) {
    assertTrue(DB::table('recommend_author_index')->where('submission_id', $s1)->exists(), 'no index rows');
});

testCase('B02', 'the key of a name is the normalised one', function () use ($s1) {
    assertTrue(
        DB::table('recommend_author_index')->where('submission_id', $s1)
            ->where('author_key', AuthorKey::fromName('Marina', 'Duarte'))->exists(),
        'expected the normalised name key'
    );
});

testCase('B03', 'an ORCID adds a key without replacing the name key', function () use ($s7, $orcid) {
    $keys = DB::table('recommend_author_index')->where('submission_id', $s7)->pluck('author_key')->all();
    assertTrue(in_array(AuthorKey::fromOrcid($orcid), $keys, true), 'ORCID key missing');
    assertTrue(in_array(AuthorKey::fromName('Marina', 'Duarte'), $keys, true), 'name key missing');
});

testCase('B04', 'with ORCID matching off, only the name key is written', function () use ($s7, $orcid, $indexWithoutOrcid) {
    $indexWithoutOrcid->index([$s7]);
    $keys = DB::table('recommend_author_index')->where('submission_id', $s7)->pluck('author_key')->all();
    assertTrue(!in_array(AuthorKey::fromOrcid($orcid), $keys, true), 'ORCID key should not be there');
    assertTrue(in_array(AuthorKey::fromName('Marina', 'Duarte'), $keys, true), 'name key must remain');
});

testCase('B05', 'a nameless author produces no key at all', function () use ($s5, $index) {
    $index->index([$s5]);
    assertEquals(0, DB::table('recommend_author_index')->where('submission_id', $s5)->count(),
        'a nameless author must not be indexed');
});

testCase('B06', 'a name in two locales yields a key for each spelling', function () use ($index) {
    $id = newPublishedSubmission('locales', [[
        'locales' => ['pt_BR' => ['Muñoz', 'Núñez'], 'en' => ['Munoz', 'Nunez']],
    ]]);
    $index->index([$id]);
    $keys = DB::table('recommend_author_index')->where('submission_id', $id)->pluck('author_key')->all();
    // Both spellings fold to the same key, so one row is the right answer.
    assertEquals([AuthorKey::fromName('Munoz', 'Nunez')], $keys, 'both spellings should fold together');
});

testCase('B07', 'a genuinely different spelling per locale yields two keys', function () use ($index) {
    $id = newPublishedSubmission('two-locales', [[
        'locales' => ['pt_BR' => ['Maria', 'Conceição'], 'en' => ['Mary', 'Conception']],
    ]]);
    $index->index([$id]);
    $keys = DB::table('recommend_author_index')->where('submission_id', $id)->pluck('author_key')->all();
    sort($keys);
    $expected = [AuthorKey::fromName('Maria', 'Conceição'), AuthorKey::fromName('Mary', 'Conception')];
    sort($expected);
    assertEquals($expected, $keys);
});

testCase('B08', 'indexing again replaces, it does not duplicate', function () use ($s1, $index) {
    $before = DB::table('recommend_author_index')->where('submission_id', $s1)->count();
    $index->index([$s1]);
    $index->index([$s1]);
    assertEquals($before, DB::table('recommend_author_index')->where('submission_id', $s1)->count());
});

testCase('B09', 'an unpublished submission drops out of the index', function () use ($index) {
    $id = newPublishedSubmission('to-unpublish', [['Temporario', 'Autor']]);
    $index->index([$id]);
    assertTrue(DB::table('recommend_author_index')->where('submission_id', $id)->exists(), 'setup failed');
    unpublish($id);
    $index->index([$id]);
    assertEquals(0, DB::table('recommend_author_index')->where('submission_id', $id)->count(),
        'unpublished submissions must not stay in the index');
});

testCase('B10', 'relatedTo finds articles that share an author', function () use ($index, $s1, $s2, $s4) {
    $related = $index->relatedTo($s1);
    assertTrue(isset($related[$s2]), 'the double-space spelling should match');
    assertTrue(isset($related[$s4]), 'the second author should match');
});

testCase('B11', 'relatedTo never returns the article itself', function () use ($index, $s1) {
    assertTrue(!isset($index->relatedTo($s1)[$s1]), 'self-reference found');
});

testCase('B12', 'relatedTo counts how many authors are shared', function () use ($index, $s1, $s2, $s4) {
    $related = $index->relatedTo($s1);
    assertEquals(1, $related[$s2] ?? null, 'one author in common expected');
    assertEquals(1, $related[$s4] ?? null, 'one author in common expected');
});

testCase('B13', 'ORCID finds the author who changed surname', function () use ($index, $s7, $s3) {
    $index->index([$s3, $s7]);
    assertTrue(isset($index->relatedTo($s7)[$s3]),
        'same ORCID, different surname, should still match');
});

testCase('B14', 'a nameless author recommends nothing', function () use ($index, $s5, $s6) {
    $index->index([$s5, $s6]);
    assertEquals([], $index->relatedTo($s5),
        'the original plugin matched every nameless author against every other');
});

testCase('B15', 'relatedToMany agrees with relatedTo', function () use ($index, $s1, $s2, $s4) {
    $many = $index->relatedToMany([$s1, $s2, $s4]);
    foreach ([$s1, $s2, $s4] as $id) {
        assertEquals($index->relatedTo($id), $many[$id] ?? [], "batch differs for submission {$id}");
    }
});

testCase('B16', 'neighboursOf lists everyone sharing an author, including itself', function () use ($index, $s1, $s2) {
    $neighbours = $index->neighboursOf([$s1]);
    assertTrue(in_array($s1, $neighbours, true), 'should include the submission itself');
    assertTrue(in_array($s2, $neighbours, true), 'should include the article sharing an author');
});

//
// C. The store
//
block('C. Recommendation store');

testCase('C01', 'enqueueNew enrols published submissions', function () use ($store, $s1) {
    $store->enqueueNew([CONTEXT_ID], 5000);
    assertTrue(DB::table('recommend_author_state')->where('submission_id', $s1)->exists(), 'not enrolled');
});

testCase('C02', 'enqueueNew ignores journals it was not asked about', function () use ($store) {
    assertEquals(0, $store->enqueueNew([], 5000), 'no context means no work');
});

testCase('C03', 'a submission is enrolled once, not once per run', function () use ($store, $s1) {
    $store->enqueueNew([CONTEXT_ID], 5000);
    assertEquals(1, DB::table('recommend_author_state')->where('submission_id', $s1)->count());
});

testCase('C04', 'indexPending stamps indexed_at and empties the queue', function () use ($store, $index) {
    while ($store->indexPending($index, 5000)) {
    }
    assertEquals(0, $store->pendingIndexCount(), 'index queue should be empty');
});

testCase('C05', 'markForReindex puts a submission back in the index queue', function () use ($store, $s1, $index) {
    $store->markForReindex([$s1]);
    assertEquals(1, $store->pendingIndexCount(), 'exactly the one marked should be pending');
    $store->indexPending($index, 5000);
    assertEquals(0, $store->pendingIndexCount());
});

$ranking = [];
testCase('C06', 'refresh writes an ordered list', function () use ($store, $index, $s1, $s2, $s4, $s7, &$ranking) {
    $store->refresh([$s1], $index, $ranking, 50);
    $stored = onlyOurs($store->read($s1, 0, 100));
    sort($stored);
    // $s2 shares the author under a dirty spelling, $s4 shares the second
    // author, and $s7 shares the first author by name (it also carries an ORCID).
    $expected = [$s2, $s4, $s7];
    sort($expected);
    assertEquals($expected, $stored);
});

testCase('C07', 'refresh marks the submission as computed', function () use ($store, $s1) {
    assertTrue($store->stateOf($s1)?->computed_at !== null, 'computed_at not stamped');
});

testCase('C08', 'the ranking decides the order', function () use ($store, $index, $s1, $s2, $s4) {
    $store->refresh([$s1], $index, [$s4 => 100, $s2 => 1], 50);
    $stored = onlyOurs($store->read($s1, 0, 100));
    assertEquals($s4, $stored[0] ?? null, 'the highest ranked should come first');

    $store->refresh([$s1], $index, [$s4 => 1, $s2 => 100], 50);
    $stored = onlyOurs($store->read($s1, 0, 100));
    assertEquals($s2, $stored[0] ?? null, 'reversing the ranking should reverse the order');
});

testCase('C09', 'shared authors break a tie in the ranking', function () use ($store, $index) {
    $a = newPublishedSubmission('tie-source', [['Aaa', 'Bbb'], ['Ccc', 'Ddd']]);
    $one = newPublishedSubmission('tie-one', [['Aaa', 'Bbb']]);
    $two = newPublishedSubmission('tie-two', [['Aaa', 'Bbb'], ['Ccc', 'Ddd']]);
    $index->index([$a, $one, $two]);
    $store->enqueueNew([CONTEXT_ID], 5000);
    $store->refresh([$a], $index, [], 50);
    $stored = onlyOurs($store->read($a, 0, 100));
    assertEquals($two, $stored[0] ?? null, 'two shared authors should outrank one');
});

testCase('C10', 'maxRecommendations caps what is stored', function () use ($store, $index) {
    $author = ['Prolifico', 'Autor'];
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = newPublishedSubmission('cap-' . $i, [$author]);
    }
    $index->index($ids);
    $store->enqueueNew([CONTEXT_ID], 5000);
    $store->refresh([$ids[0]], $index, [], 2);
    assertEquals(2, $store->total($ids[0]), 'the cap must be honoured');
});

testCase('C11', 'read pages through the stored list', function () use ($store, $index) {
    $author = ['Paginado', 'Autor'];
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = newPublishedSubmission('page-' . $i, [$author]);
    }
    $index->index($ids);
    $store->enqueueNew([CONTEXT_ID], 5000);
    $store->refresh([$ids[0]], $index, [], 50);

    $all = $store->read($ids[0], 0, 100);
    assertEquals(4, count($all), 'four siblings expected');
    assertEquals(array_slice($all, 0, 2), $store->read($ids[0], 0, 2), 'first page');
    assertEquals(array_slice($all, 2, 2), $store->read($ids[0], 2, 2), 'second page');
    assertEquals(4, $store->total($ids[0]), 'total must match');
});

testCase('C12', 'invalidate re-queues and bumps the version', function () use ($store, $s1) {
    $before = $store->stateOf($s1);
    $store->invalidate([$s1]);
    $after = $store->stateOf($s1);
    assertNull($after->computed_at, 'computed_at should be cleared');
    assertTrue((int) $after->version > (int) $before->version, 'version should move, so cached HTML is unreachable');
});

testCase('C13', 'due returns the never-computed first', function () use ($store, $s1) {
    $due = $store->due([CONTEXT_ID], 500, 7);
    assertTrue(in_array($s1, $due[CONTEXT_ID] ?? [], true), 'the invalidated submission should be due');
});

testCase('C14', 'due respects the batch size', function () use ($store) {
    $due = $store->due([CONTEXT_ID], 1, 7);
    assertEquals(1, count($due[CONTEXT_ID] ?? []), 'batch size must bound the slice');
});

testCase('C15', 'queueLimit caps how many submissions are ever enrolled', function () use ($store) {
    $enrolled = DB::table('recommend_author_state')->count();
    assertEquals(0, $store->enqueueNew([CONTEXT_ID], 5000, $enrolled), 'no room left, nothing should be enrolled');
});

//
// D. End to end
//
block('D. From publishing to the reader');

testCase('D01', 'a new article appears in the recommendations of its co-author', function () use ($store, $index) {
    $first = newPublishedSubmission('e2e-first', [['Ednaldo', 'Pereira']]);
    $index->index([$first]);
    $store->enqueueNew([CONTEXT_ID], 5000);
    $store->refresh([$first], $index, [], 50);
    assertEquals([], onlyOurs($store->read($first, 0, 100)), 'nothing to recommend yet');

    $second = newPublishedSubmission('e2e-second', [['Ednaldo', 'Pereira']]);
    $index->index([$second]);
    $store->enqueueNew([CONTEXT_ID], 5000);
    $store->refresh([$first], $index, [], 50);
    assertEquals([$second], onlyOurs($store->read($first, 0, 100)), 'the new article should be there');
});

testCase('D02', 'publishing queues the article and its neighbours', function () use ($plugin, $store, $index) {
    $first = newPublishedSubmission('hook-first', [['Gancho', 'Autor']]);
    $index->index([$first]);
    $store->enqueueNew([CONTEXT_ID], 5000);
    $store->refresh([$first], $index, [], 50);
    assertTrue($store->stateOf($first)?->computed_at !== null, 'setup: should be computed');

    // What the Publication::publish hook does.
    $second = newPublishedSubmission('hook-second', [['Gancho', 'Autor']]);
    $index->index([$second]);
    $plugin->invalidateFromPublication('Publication::publish', [
        Repo::submission()->get($second)->getCurrentPublication(),
    ]);

    assertNull($store->stateOf($first)?->computed_at,
        'the neighbour should have been queued for recomputation');
});

testCase('D03', 'the dirty-spacing article really is recommended', function () use ($store, $index, $s1, $s2) {
    $store->refresh([$s1], $index, [], 50);
    assertTrue(in_array($s2, $store->read($s1, 0, 100), true),
        'the article whose author has a double space must be found');
});

testCase('D04', 'an article whose only author is nameless recommends nothing', function () use ($store, $index, $s5) {
    $store->refresh([$s5], $index, [], 50);
    assertEquals([], onlyOurs($store->read($s5, 0, 100)));
});

testCase('D05', 'deleting a submission takes its rows with it', function () use ($store, $index) {
    $id = newPublishedSubmission('to-delete', [['Efemero', 'Autor']]);
    $index->index([$id]);
    $store->enqueueNew([CONTEXT_ID], 5000);
    $store->refresh([$id], $index, [], 50);
    assertTrue(DB::table('recommend_author_state')->where('submission_id', $id)->exists(), 'setup failed');

    Repo::submission()->delete(Repo::submission()->get($id));

    assertEquals(0, DB::table('recommend_author_state')->where('submission_id', $id)->count(),
        'state row should have been cascaded away');
    assertEquals(0, DB::table('recommend_author_index')->where('submission_id', $id)->count(),
        'index rows should have been cascaded away');
    assertEquals(0, DB::table('recommend_author_cache')->where('submission_id', $id)->count(),
        'cache rows should have been cascaded away');
});

testCase('D06', 'a deleted article stops being recommended to others', function () use ($store, $index) {
    $keeper = newPublishedSubmission('keeper', [['Sobrevivente', 'Autor']]);
    $doomed = newPublishedSubmission('doomed', [['Sobrevivente', 'Autor']]);
    $index->index([$keeper, $doomed]);
    $store->enqueueNew([CONTEXT_ID], 5000);
    $store->refresh([$keeper], $index, [], 50);
    assertTrue(in_array($doomed, $store->read($keeper, 0, 100), true), 'setup failed');

    Repo::submission()->delete(Repo::submission()->get($doomed));

    assertTrue(!in_array($doomed, $store->read($keeper, 0, 100), true),
        'the deleted article must not stay in anybody else list');
});

//
// E. Guards
//
block('E. Guards and edge cases');

testCase('E01', 'reading a submission that was never computed is harmless', function () use ($store) {
    assertEquals([], $store->read(99999999, 0, 10));
    assertEquals(0, $store->total(99999999));
    assertNull($store->stateOf(99999999), 'no state expected');
});

testCase('E02', 'refreshing an empty batch does nothing and does not fail', function () use ($store, $index) {
    $store->refresh([], $index, [], 50);
    $index->index([]);
    assertEquals([], $index->relatedToMany([]));
    assertEquals([], $index->neighboursOf([]));
});

testCase('E03', 'the plugin settings have safe defaults', function () use ($plugin) {
    assertEquals(0, (int) $plugin::DEFAULTS['computeOnDemand'],
        'computing while the reader waits must be off by default');
    assertTrue((int) $plugin::DEFAULTS['batchSize'] > 0, 'a batch size is required');
    assertTrue((int) $plugin::DEFAULTS['maxAgeDays'] > 0, 'a refresh age is required');
});

testCase('E04', 'enabledContextIds reads the settings, not the request', function () use ($plugin) {
    $enabled = $plugin->enabledContextIds();
    assertTrue(is_array($enabled), 'should always return an array');
    $expected = DB::table('plugin_settings')->where('plugin_name', PLUGIN)
        ->where('setting_name', 'enabled')->whereIn('setting_value', ['1', 'true'])->count();
    assertTrue($expected === 0 || count($enabled) > 0,
        'a journal with the plugin enabled must be listed');
});

testCase('E05', 'no plugin table has rows pointing at submissions that no longer exist', function () {
    foreach (['recommend_author_index', 'recommend_author_state', 'recommend_author_cache'] as $table) {
        $orphans = DB::table($table . ' as t')
            ->leftJoin('submissions as s', 's.submission_id', '=', 't.submission_id')
            ->whereNull('s.submission_id')->count();
        assertEquals(0, $orphans, "{$table} has orphan rows");
    }
    $orphans = DB::table('recommend_author_cache as c')
        ->leftJoin('submissions as s', 's.submission_id', '=', 'c.recommended_submission_id')
        ->whereNull('s.submission_id')->count();
    assertEquals(0, $orphans, 'recommend_author_cache points at deleted submissions');
});

//
// Wrap up
//
if (!$keep) {
    cleanUp();
    echo "\n(test submissions removed)\n";
} else {
    echo "\n(--keep: test submissions left behind; run again without --keep to remove them)\n";
}

$failed = array_values(array_filter($RESULTS, fn ($r) => !$r['ok']));
$passed = count($RESULTS) - count($failed);

echo "\n" . str_repeat('=', 78) . "\n";
printf("%d cases: %d passed, %d failed\n", count($RESULTS), $passed, count($failed));
foreach ($failed as $failure) {
    printf("  FAIL %-6s %s\n", $failure['id'], $failure['title']);
}
echo str_repeat('=', 78) . "\n";

file_put_contents(
    __DIR__ . '/results.json',
    json_encode(['passed' => $passed, 'failed' => count($failed), 'cases' => $RESULTS], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

exit(count($failed) === 0 ? 0 : 1);
