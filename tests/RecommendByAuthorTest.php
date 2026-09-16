<?php

/**
 * @file plugins/generic/recommendByAuthor/tests/RecommendByAuthorTest.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RecommendByAuthorTest
 *
 * @brief The index, the store and the path from publishing an article to the
 *        list the reader is shown.
 *
 *        These checks create and delete submissions of their own, so they run
 *        only where that is acceptable: an installation with many published
 *        articles looks like a live journal and the suite skips itself. The
 *        rules of the author key alone are in AuthorKeyTest.
 */

namespace APP\plugins\generic\recommendByAuthor\tests;

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\plugins\generic\recommendByAuthor\classes\AuthorIndex;
use APP\plugins\generic\recommendByAuthor\classes\AuthorKey;
use APP\plugins\generic\recommendByAuthor\classes\RecommendationStore;
use APP\plugins\generic\recommendByAuthor\RecommendByAuthorPlugin;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\core\PKPRequest;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\tests\PKPTestCase;
use PKP\userGroup\UserGroup;

#[CoversClass(AuthorIndex::class)]
#[CoversClass(RecommendationStore::class)]
class RecommendByAuthorTest extends PKPTestCase
{
    /** The journal these fixtures belong to. */
    private const CONTEXT_ID = 1;

    /** Everything created here carries this marker in the title. */
    private const MARKER = '[RBA-TESTS]';

    /** Above this many published articles the installation is treated as a live journal. */
    private const PRODUCTION_LOOKS_LIKE = 100;

    private const ORCID = 'https://orcid.org/0000-0002-1825-0097';

    /** Submission ids created by this class, in creation order. */
    private static array $created = [];

    /** The fixtures shared by the tests, by name. */
    private static array $ids = [];

    private AuthorIndex $index;
    private RecommendationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessTestInstallation();
        $this->pinContext();

        $this->index = new AuthorIndex(true);
        $this->store = new RecommendationStore();

        if (!self::$ids) {
            $this->buildFixtures();
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::removeCreated();
        self::$ids = [];
        parent::tearDownAfterClass();
    }

    /**
     * The suite creates and deletes submissions: acceptable on a test
     * installation, never on a live journal, and the only difference visible
     * from here is how much is published.
     */
    private function skipUnlessTestInstallation(): void
    {
        foreach (['recommend_author_index', 'recommend_author_cache', 'recommend_author_state'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->markTestSkipped('the plugin has never been enabled here, so its tables are missing');
            }
        }
        if (!Application::getContextDAO()->getById(self::CONTEXT_ID)) {
            $this->markTestSkipped('journal ' . self::CONTEXT_ID . ' does not exist');
        }
        $published = DB::table('submissions')->where('status', Submission::STATUS_PUBLISHED)->count();
        if ($published > self::PRODUCTION_LOOKS_LIKE) {
            $this->markTestSkipped('this installation has ' . $published . ' published articles: it looks like a live journal');
        }
    }

    /**
     * There is no URL on the command line, so the journal is pinned on the
     * router: without it getContext() answers null half way through.
     */
    private function pinContext(): void
    {
        $request = Application::get()->getRequest();
        $context = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $router = new class () extends PageRouter {
            public $pinned;

            public function getContext(PKPRequest $request, bool $forceReload = false): ?\PKP\context\Context
            {
                return $this->pinned;
            }
        };
        $router->setApplication(Application::get());
        $router->pinned = $context;
        $request->setRouter($router);
    }

    /**
     * A published submission with the given authors, each one
     * [givenName, familyName, orcid|null] or ['locales' => [locale => [given, family]], 'orcid' => …].
     */
    private function publishedSubmission(string $title, array $authors): int
    {
        $context = Application::getContextDAO()->getById(self::CONTEXT_ID);
        $userGroupId = UserGroup::withContextIds([self::CONTEXT_ID])->withRoleIds([Role::ROLE_ID_AUTHOR])->first()?->id
            ?? UserGroup::withContextIds([self::CONTEXT_ID])->first()?->id;
        $sectionId = DB::table('sections')->where('journal_id', self::CONTEXT_ID)->value('section_id');
        $issueId = DB::table('issues')->where('journal_id', self::CONTEXT_ID)->value('issue_id');

        $submission = Repo::submission()->newDataObject([
            'contextId' => self::CONTEXT_ID,
            'status' => Submission::STATUS_QUEUED,
            'submissionProgress' => '',
            'stageId' => WORKFLOW_STAGE_ID_PRODUCTION,
            'locale' => 'en',
        ]);
        $publication = Repo::publication()->newDataObject([
            'title' => ['en' => self::MARKER . ' ' . $title],
            'sectionId' => $sectionId,
            'issueId' => $issueId,
            'locale' => 'en',
            'status' => Submission::STATUS_QUEUED,
            'datePublished' => '2026-01-15',
        ]);
        $submissionId = Repo::submission()->add($submission, $publication, $context);
        self::$created[] = $submissionId;

        $submission = Repo::submission()->get($submissionId);
        $publication = $submission->getCurrentPublication();

        foreach ($authors as $seq => $spec) {
            $locales = $spec['locales'] ?? ['en' => [$spec[0] ?? '', $spec[1] ?? '']];
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
                'email' => 'rba.tests+' . $seq . '@example.org',
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

    /**
     * The set every test starts from: one author shared by three articles, one
     * of them spelled untidily, a stranger, two nameless authors and the same
     * person again with an ORCID and another surname.
     */
    private function buildFixtures(): void
    {
        self::removeCreated();

        self::$ids = [
            'one' => $this->publishedSubmission('one', [['Marina', 'Duarte']]),
            'two' => $this->publishedSubmission('two', [['MARINA', ' Duarte ']]),
            'three' => $this->publishedSubmission('three', [['Marina', 'Duarte Lima', self::ORCID]]),
            'four' => $this->publishedSubmission('four', [['Otávio', 'Ferreira']]),
            'nameless' => $this->publishedSubmission('five', [['', '']]),
            'namelessToo' => $this->publishedSubmission('six', [['', '']]),
            'withOrcid' => $this->publishedSubmission('seven', [['Marina', 'Duarte', self::ORCID]]),
        ];
        $this->index->index(array_values(self::$ids));
    }

    /** Removes every submission this class created, and any left by an interrupted run. */
    private static function removeCreated(): void
    {
        foreach (array_unique(self::$created) as $submissionId) {
            if ($submission = Repo::submission()->get($submissionId)) {
                Repo::submission()->delete($submission);
            }
        }
        $stale = DB::table('publication_settings as ps')
            ->join('publications as p', 'p.publication_id', '=', 'ps.publication_id')
            ->where('ps.setting_name', 'title')
            ->where('ps.setting_value', 'like', '%' . self::MARKER . '%')
            ->distinct()->pluck('p.submission_id');
        foreach ($stale as $submissionId) {
            if ($submission = Repo::submission()->get((int) $submissionId)) {
                Repo::submission()->delete($submission);
            }
        }
        self::$created = [];
    }

    /** Only this class's own submissions, so a populated journal does not disturb an assertion. */
    private function onlyOurs(array $ids): array
    {
        return array_values(array_intersect($ids, self::$created));
    }

    private function keysOf(int $submissionId): array
    {
        return DB::table('recommend_author_index')->where('submission_id', $submissionId)->pluck('author_key')->all();
    }

    public function testAPublishedSubmissionIsIndexedUnderTheNormalisedKeyOfItsAuthor(): void
    {
        $this->assertNotEmpty($this->keysOf(self::$ids['one']), 'the article was not indexed');
        $this->assertContains(AuthorKey::fromName('Marina', 'Duarte'), $this->keysOf(self::$ids['one']));
        // The untidy spelling lands on the very same key.
        $this->assertContains(AuthorKey::fromName('Marina', 'Duarte'), $this->keysOf(self::$ids['two']));
    }

    public function testAnOrcidAddsAKeyWithoutReplacingTheNameOneAndCanBeTurnedOff(): void
    {
        $keys = $this->keysOf(self::$ids['withOrcid']);
        $this->assertContains(AuthorKey::fromOrcid(self::ORCID), $keys);
        $this->assertContains(AuthorKey::fromName('Marina', 'Duarte'), $keys);

        (new AuthorIndex(false))->index([self::$ids['withOrcid']]);
        $withoutOrcid = $this->keysOf(self::$ids['withOrcid']);
        $this->assertNotContains(AuthorKey::fromOrcid(self::ORCID), $withoutOrcid);
        $this->assertContains(AuthorKey::fromName('Marina', 'Duarte'), $withoutOrcid);

        // Put the fixture back as the other tests expect it.
        $this->index->index([self::$ids['withOrcid']]);
    }

    public function testANamelessAuthorIsNotIndexedAndIndexingAgainDoesNotDuplicate(): void
    {
        $this->assertSame(0, DB::table('recommend_author_index')->where('submission_id', self::$ids['nameless'])->count());

        $before = DB::table('recommend_author_index')->where('submission_id', self::$ids['one'])->count();
        $this->index->index([self::$ids['one']]);
        $this->assertSame($before, DB::table('recommend_author_index')->where('submission_id', self::$ids['one'])->count());
    }

    public function testEachSpellingOfANameInItsOwnLanguageGetsAKey(): void
    {
        $id = $this->publishedSubmission('locales', [[
            'locales' => ['en' => ['Munoz', 'Nunez'], 'pt_BR' => ['Muñoz', 'Núñez']],
        ]]);
        $this->index->index([$id]);

        // Both spellings fold to the same key, so one key is enough.
        $this->assertSame([AuthorKey::fromName('Munoz', 'Nunez')], array_values(array_unique($this->keysOf($id))));

        $other = $this->publishedSubmission('locales two', [[
            'locales' => ['en' => ['Mary', 'Smith'], 'pt_BR' => ['Maria', 'Ferreira']],
        ]]);
        $this->index->index([$other]);
        $keys = $this->keysOf($other);
        $this->assertContains(AuthorKey::fromName('Mary', 'Smith'), $keys);
        $this->assertContains(AuthorKey::fromName('Maria', 'Ferreira'), $keys);
    }

    public function testAnUnpublishedSubmissionDropsOutOfTheIndex(): void
    {
        $id = $this->publishedSubmission('unpublished', [['Marina', 'Duarte']]);
        $this->index->index([$id]);
        $this->assertNotEmpty($this->keysOf($id));

        $submission = Repo::submission()->get($id);
        Repo::publication()->unpublish($submission->getCurrentPublication());
        $this->index->index([$id]);
        $this->assertSame([], $this->keysOf($id));
    }

    public function testRelatedToFindsTheArticlesThatShareAnAuthorAndNeverTheArticleItself(): void
    {
        $related = $this->onlyOurs(array_keys($this->index->relatedTo(self::$ids['one'])));

        $this->assertContains(self::$ids['two'], $related, 'the same author, spelled untidily');
        $this->assertNotContains(self::$ids['four'], $related, 'a different author');
        $this->assertNotContains(self::$ids['one'], $related, 'an article must not recommend itself');

        // The ORCID finds the author who changed surname, whom the name alone would miss.
        $byOrcid = $this->onlyOurs(array_keys($this->index->relatedTo(self::$ids['three'])));
        $this->assertContains(self::$ids['withOrcid'], $byOrcid);

        // A nameless author recommends nothing.
        $this->assertSame([], $this->onlyOurs(array_keys($this->index->relatedTo(self::$ids['nameless']))));
    }

    public function testTheStoreWritesAnOrderedListAndPagesThroughIt(): void
    {
        $this->store->enqueueNew([self::CONTEXT_ID]);
        $this->store->refresh([self::$ids['one']], $this->index, [], 50);

        $total = $this->store->total(self::$ids['one']);
        $this->assertGreaterThan(0, $total, 'nothing was stored for the article');

        $all = $this->store->read(self::$ids['one'], 0, $total);
        $this->assertCount($total, $all);
        $this->assertNotContains(self::$ids['one'], $all, 'the article must not recommend itself');

        // Reading page by page gives the same list, in the same order.
        $paged = array_merge(
            $this->store->read(self::$ids['one'], 0, 1),
            $this->store->read(self::$ids['one'], 1, $total)
        );
        $this->assertSame($all, $paged);

        $state = $this->store->stateOf(self::$ids['one']);
        $this->assertNotNull($state, 'the article was not marked as computed');
    }

    public function testTheStoredListIsCappedByTheConfiguredMaximum(): void
    {
        $shared = [['Cap', 'Author']];
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->publishedSubmission('cap ' . $i, $shared);
        }
        $this->index->index($ids);
        $this->store->refresh([$ids[0]], $this->index, [], 2);

        $this->assertLessThanOrEqual(2, $this->store->total($ids[0]));
    }

    public function testInvalidatingAnArticleQueuesItAgain(): void
    {
        $this->store->refresh([self::$ids['one']], $this->index, [], 50);
        $before = $this->store->stateOf(self::$ids['one']);

        $this->store->invalidate([self::$ids['one']]);
        $after = $this->store->stateOf(self::$ids['one']);

        $this->assertNotNull($after);
        $this->assertNotEquals($before, $after, 'invalidating changed nothing');
    }

    public function testAnArticleThatWasNeverComputedAnswersEmptyAndAnEmptyBatchIsHarmless(): void
    {
        $unknown = (int) DB::table('submissions')->max('submission_id') + 100000;

        $this->assertSame([], $this->store->read($unknown, 0, 10));
        $this->assertSame(0, $this->store->total($unknown));
        $this->assertNull($this->store->stateOf($unknown));

        $this->store->refresh([], $this->index, [], 50);
        $this->index->index([]);
        $this->assertSame([], $this->index->relatedToMany([]));
        $this->assertSame([], $this->index->neighboursOf([]));
    }

    public function testDeletingASubmissionTakesItsRowsWithIt(): void
    {
        $id = $this->publishedSubmission('deleted', [['Marina', 'Duarte']]);
        $this->index->index([$id]);
        $this->store->refresh([$id], $this->index, [], 50);
        $this->assertNotEmpty($this->keysOf($id));

        Repo::submission()->delete(Repo::submission()->get($id));

        $this->assertSame([], $this->keysOf($id));
        foreach (['recommend_author_index', 'recommend_author_state', 'recommend_author_cache'] as $table) {
            $this->assertSame(0, DB::table($table)->where('submission_id', $id)->count(), $table . ' kept rows of a deleted article');
        }
        $this->assertSame(0, DB::table('recommend_author_cache')->where('recommended_submission_id', $id)->count());
    }

    public function testNoTableKeepsRowsOfArticlesThatNoLongerExist(): void
    {
        foreach (['recommend_author_index', 'recommend_author_state', 'recommend_author_cache'] as $table) {
            $orphans = DB::table($table . ' as t')
                ->leftJoin('submissions as s', 's.submission_id', '=', 't.submission_id')
                ->whereNull('s.submission_id')->count();
            $this->assertSame(0, $orphans, $table . ' has orphan rows');
        }
        $orphans = DB::table('recommend_author_cache as c')
            ->leftJoin('submissions as s', 's.submission_id', '=', 'c.recommended_submission_id')
            ->whereNull('s.submission_id')->count();
        $this->assertSame(0, $orphans, 'the cache points at deleted articles');
    }

    public function testTheDefaultsCannotSlowAJournalDown(): void
    {
        $defaults = RecommendByAuthorPlugin::DEFAULTS;

        $this->assertSame(0, (int) $defaults['computeOnDemand'], 'computing while the reader waits must be off by default');
        $this->assertGreaterThan(0, (int) $defaults['batchSize']);
        $this->assertGreaterThan(0, (int) $defaults['maxAgeDays']);

        PluginRegistry::loadCategory('generic', false, self::CONTEXT_ID);
        $plugin = PluginRegistry::getPlugin('generic', 'recommendbyauthorplugin');
        $this->assertNotNull($plugin, 'the plugin is not installed here');
        // The journals it works on come from the settings, never from the request.
        $this->assertIsArray($plugin->enabledContextIds());
    }
}
