<?php declare(strict_types=1);

namespace Tests\Unit\Movary\Service\Journal;

use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use Movary\Service\Journal\LibraryMetadata;
use Movary\Service\Journal\LibraryRepository;
use Movary\Service\Journal\LibrarySchema;
use Movary\Service\Journal\PersonalLibrary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonalLibrary::class)]
#[CoversClass(LibraryRepository::class)]
#[CoversClass(LibrarySchema::class)]
class PersonalLibraryTest extends TestCase
{
    private LibraryRepository $repository;
    private PersonalLibrary $library;

    protected function setUp() : void
    {
        $this->repository = new LibraryRepository(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        foreach (LibrarySchema::statements() as $sql) {
            $this->repository->db->executeStatement($sql);
        }
        foreach ([1, 2] as $owner) {
            $this->repository->db->insert('journal_collection', ['user_id' => $owner, 'revision' => 1, 'source_hash' => 'fixture', 'migrated_at' => '2026-01-01']);
        }
        $metadata = $this->createStub(LibraryMetadata::class);
        $metadata->method('type')->willReturnArgument(0);
        $metadata->method('fetch')->willReturnCallback(static fn(string $type, int $id) : array => [
            'tmdbId' => $id, 'mediaType' => $type, 'title' => 'Fixture ' . $id, 'year' => '1995', 'aliases' => ['Original fixture'],
            'people' => [['id' => 9, 'name' => 'A filmmaker', 'role' => $type === 'tv' ? 'Creator' : 'Director', 'scope' => $type]],
            'genres' => [['id' => 8, 'name' => 'Drama']], 'language' => 'en', 'keywords' => [], 'countries' => [],
            'provenance' => ['source' => 'TMDB', 'fetchedAt' => '2026-01-01T00:00:00Z']]);
        $this->library = new PersonalLibrary($this->repository, $metadata, $this->createStub(\Movary\Service\Journal\LibraryCheckpoint::class));
    }

    public function testInterestDoesNotCreateViewingAndMovieTvIdsCannotCollide() : void
    {
        $first = $this->apply(['action' => 'set_interest', 'mediaType' => 'movie', 'tmdbId' => 42, 'interest' => 'want']);
        $second = $this->apply(['action' => 'set_interest', 'mediaType' => 'tv', 'tmdbId' => 42, 'interest' => 'not_interested']);
        self::assertNotSame($first['titleIds'], $second['titleIds']);
        self::assertSame(0, (int)$this->repository->db->fetchOne('SELECT COUNT(*) FROM journal_event'));
        self::assertSame(2, $this->library->search(1, ['status' => 'unknown'])['total']);
        self::assertSame(0, $this->library->search(1, ['status' => 'seen'])['total']);
    }

    public function testCommitRetriesAndPreviewRetriesReturnTheOriginalReceipt() : void
    {
        $changes = [['action' => 'log_watch', 'mediaType' => 'movie', 'tmdbId' => 42, 'reaction' => 'Enjoyed', 'date' => null]];
        $plan = $this->library->preview(1, $changes, 'same-request-123456');
        self::assertSame(0, $this->library->search(1)['total']);
        $receipt = $this->library->commit(1, $plan['id'], $plan['approval']);
        self::assertSame($receipt, $this->library->commit(1, $plan['id'], $plan['approval']));
        self::assertSame($receipt, $this->library->preview(1, $changes, 'same-request-123456')['receipt']);
        $history = $this->library->history(1, $receipt['titleIds'][0]);
        self::assertCount(1, $history['items']);
        self::assertNull($history['items'][0]['watched_on']);
        self::assertSame('Enjoyed', $history['items'][0]['reaction']);
    }

    public function testRewatchAndAssessmentRemainDifferentEvents() : void
    {
        $receipt = $this->apply(['action' => 'confirm_seen', 'mediaType' => 'movie', 'tmdbId' => 42, 'reaction' => 'Loved']);
        $id = $receipt['titleIds'][0];
        $this->apply(['action' => 'log_watch', 'titleId' => $id, 'date' => '2026-10-18', 'reaction' => 'Liked']);
        $this->apply(['action' => 'edit_assessment', 'titleId' => $id, 'reaction' => 'Enjoyed', 'note' => 'Changed my mind']);
        self::assertSame(['assessment', 'viewing', 'historical_evidence'], array_column($this->library->history(1, $id)['items'], 'kind'));
        self::assertSame('Enjoyed', $this->library->title(1, $id)['title']['reaction']);
    }

    public function testSeasonReactionDoesNotReplaceShowAssessmentOrImplyCompletion() : void
    {
        $receipt = $this->apply(['action' => 'log_watch', 'mediaType' => 'tv', 'tmdbId' => 42, 'reaction' => 'Loved']);
        $id = $receipt['titleIds'][0];
        $this->apply(['action' => 'set_progress', 'titleId' => $id, 'season' => 2, 'reaction' => 'Just okay', 'progress' => 'in_progress']);
        $title = $this->library->title(1, $id)['title'];
        self::assertSame('Loved', $title['reaction']);
        self::assertSame('2', (string)$title['progress'][0]['season']);
        self::assertSame('in_progress', $title['progress'][0]['progress']);
        self::assertSame('season', $this->library->history(1, $id)['items'][0]['scope']);
    }

    public function testLatestSeasonStateRetainsOverallProgressAndAssessmentChanges() : void
    {
        $receipt = $this->apply(['action' => 'set_progress', 'mediaType' => 'tv', 'tmdbId' => 42, 'progress' => 'in_progress', 'reaction' => 'Loved']);
        $id = $receipt['titleIds'][0];
        $this->apply(['action' => 'set_progress', 'titleId' => $id, 'season' => 2, 'progress' => 'started', 'reaction' => 'Liked']);
        $this->apply(['action' => 'set_progress', 'titleId' => $id, 'season' => 2, 'progress' => 'in_progress']);
        $this->apply(['action' => 'edit_assessment', 'titleId' => $id, 'season' => 2, 'reaction' => 'Disliked']);
        $title = $this->library->title(1, $id)['title'];
        self::assertCount(2, $title['progress']);
        self::assertSame('Loved', $title['reaction']);
        self::assertSame('Disliked', $title['seasonAssessments'][0]['reaction']);
        self::assertSame('in_progress', $title['progress'][0]['progress']);
        $export = json_decode($this->library->export(1, 'json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(4, $export['events']);
        self::assertIsArray($export['events'][0]['provenance']);
        self::assertStringContainsString('Disliked', $this->library->export(1, 'csv'));
    }

    public function testStaleProposalCannotCommitAndTransactionRollsBack() : void
    {
        $plan = $this->library->preview(1, [['action' => 'set_interest', 'mediaType' => 'movie', 'tmdbId' => 42, 'interest' => 'want']]);
        $this->apply(['action' => 'set_interest', 'mediaType' => 'movie', 'tmdbId' => 43, 'interest' => 'want']);
        try {
            $this->library->commit(1, $plan['id'], $plan['approval']);
            self::fail('Stale proposals must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame(1, $this->library->search(1)['total']);
            self::assertSame(2, $this->repository->revision(1));
        }
    }

    public function testProviderRefreshDoesNotChangePersonalRevisionOrWords() : void
    {
        $receipt = $this->apply(['action' => 'log_watch', 'mediaType' => 'movie', 'tmdbId' => 42, 'reaction' => 'Enjoyed', 'note' => 'My words']);
        $id = $receipt['titleIds'][0];
        $before = $this->library->title(1, $id);
        $after = $this->library->refresh(1, $id);
        self::assertSame($before['revision'], $after['revision']);
        self::assertSame($before['title']['version'], $after['title']['version']);
        self::assertSame('My words', $after['title']['note']);
        self::assertSame(1, $this->library->history(1, $id)['total']);
    }

    public function testFiltersAndPaginationAreTheSameCollectionContract() : void
    {
        foreach ([42, 43, 44] as $id) {
            $this->apply(['action' => 'set_interest', 'mediaType' => 'movie', 'tmdbId' => $id, 'interest' => 'want']);
        }
        $filters = ['genre' => '8', 'person' => '9', 'language' => 'en', 'year' => '1995', 'query' => 'Original fixture', 'limit' => 2];
        $first = $this->library->search(1, $filters);
        $second = $this->library->search(1, $filters + ['offset' => 2]);
        self::assertSame(3, $first['total']);
        self::assertSame(2, $first['nextOffset']);
        self::assertCount(1, $second['items']);
        self::assertSame(0, $this->library->search(2)['total']);
        self::assertSame(100, $this->library->search(1, ['limit' => 10000])['limit']);
    }

    public function testApprovedInterpretationRequiresEvidence() : void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->library->preview(1, [['action' => 'save_taste', 'kind' => 'approved_insight', 'statement' => 'A proposed interpretation']]);
    }

    public function testCrossOwnerTitleAccessIsRejected() : void
    {
        $receipt = $this->apply(['action' => 'set_interest', 'mediaType' => 'movie', 'tmdbId' => 42, 'interest' => 'want']);
        $this->expectException(InvalidArgumentException::class);
        $this->library->title(2, $receipt['titleIds'][0]);
    }

    public function testInvalidCalendarDateCannotBeSaved() : void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->library->preview(1, [['action' => 'log_watch', 'mediaType' => 'movie', 'tmdbId' => 42, 'date' => '2026-02-30']]);
    }

    /** @param array<string,mixed> $change
     * @return array<string,mixed>
     */
    private function apply(array $change) : array
    {
        $plan = $this->library->preview(1, [$change]);
        return $this->library->commit(1, $plan['id'], $plan['approval']);
    }
}
