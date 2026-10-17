<?php declare(strict_types=1);

namespace Tests\Unit\Movary\Service\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use Movary\Api\Tmdb\TmdbApi;
use Movary\Domain\Movie\MovieApi;
use Movary\Domain\Movie\MovieEntity;
use Movary\Service\Journal\JournalDocument;
use Movary\Service\Journal\JournalService;
use Movary\Service\Tmdb\SyncMovie;
use Movary\ValueObject\Config;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(JournalService::class)]
class JournalServiceTest extends TestCase
{
    private string $directory;
    private Connection $db;
    private InterruptibleJournalStore $store;
    private JournalService $journal;

    protected function setUp() : void
    {
        $this->directory = sys_get_temp_dir() . '/journal-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->store = new InterruptibleJournalStore($this->directory);
        $this->store->write(1, 'WATCH_HISTORY.md', "# History\n\n| Film | Year | Status | Reaction | Watch date | Evidence / note |\n| --- | --- | --- | --- | --- | --- |\n| Heat | 1995 | Seen | Liked | | Explicitly confirmed. |\n| Unwatched suggestion | 2000 | Unconfirmed | | | Suggested only. |\n\nFranchise uncertainty stays.\n");
        $this->store->write(1, 'TASTE_PROFILE.md', 'Exact durable taste.');
        $path = $this->directory . '/database.sqlite';
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $this->db->executeStatement('CREATE TABLE movie (id INTEGER PRIMARY KEY, tmdb_id INTEGER)');
        $this->db->executeStatement('CREATE TABLE movie_user_watch_dates (movie_id INTEGER, user_id INTEGER, watched_at TEXT, plays INTEGER)');
        $this->db->insert('movie', ['id' => 1, 'tmdb_id' => 949]);
        $tmdb = $this->createStub(TmdbApi::class);
        $tmdb->method('searchMovie')->willReturn(['results' => [['id' => 949, 'title' => 'Heat', 'release_date' => '1995-12-15']], 'total_pages' => 1]);
        $movie = $this->createStub(MovieEntity::class);
        $movie->method('getId')->willReturn(1);
        $movies = $this->createStub(MovieApi::class);
        $movies->method('findByTmdbId')->willReturn($movie);
        $movies->method('addPlaysForMovieOnDate')->willReturnCallback(function (int $movieId, int $userId, ?\Movary\ValueObject\Date $date) : void {
            $this->db->insert('movie_user_watch_dates', ['movie_id' => $movieId, 'user_id' => $userId, 'watched_at' => $date === null ? null : (string)$date, 'plays' => 1]);
        });
        $config = $this->createStub(Config::class);
        $config->method('getAsString')->willReturnCallback(static fn(string $key) : string => $key === 'DATABASE_MODE' ? 'sqlite' : '..' . $path);
        $this->journal = new JournalService(new JournalDocument(), $this->store, $tmdb, $movies, $this->createStub(SyncMovie::class), $this->db, $config);
    }

    protected function tearDown() : void
    {
        $this->db->close();
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testCommitPreservesUnknownDateAndDoesNotDuplicateOnRetry() : void
    {
        $draft = $this->journal->preview(1, 'Heat (1995) | Enjoyed');
        $review = $this->journal->review(1, $draft['id'], []);
        $first = $this->journal->commit(1, $draft['id'], $review['approval']);
        $second = $this->journal->commit(1, $draft['id'], $review['approval']);

        self::assertSame($first, $second);
        self::assertSame([['watched_at' => null, 'plays' => 1]], $this->db->fetchAllAssociative('SELECT watched_at, plays FROM movie_user_watch_dates'));
        $context = $this->journal->context(1);
        self::assertSame('Enjoyed', $context['films'][0]['reaction']);
        self::assertSame('Unconfirmed', $context['films'][1]['status']);
        self::assertFileExists($this->directory . '/' . $first['backup'] . '.sqlite');
    }

    public function testDatedWatchIsReadBackExactly() : void
    {
        $draft = $this->journal->preview(1, 'Heat (1995) | Liked | 2026-10-16');
        $review = $this->journal->review(1, $draft['id'], []);
        $this->journal->commit(1, $draft['id'], $review['approval']);

        self::assertSame('2026-10-16', $this->db->fetchOne('SELECT watched_at FROM movie_user_watch_dates'));
    }

    public function testExistingDatedPlayIsNotAnExtraUndatedWatch() : void
    {
        $this->db->insert('movie_user_watch_dates', ['movie_id' => 1, 'user_id' => 1, 'watched_at' => '2020-01-01', 'plays' => 2]);
        $draft = $this->journal->preview(1, 'Heat (1995) | Loved');
        $review = $this->journal->review(1, $draft['id'], []);
        self::assertSame('already-watched', $review['changes'][0]['play']);
        $this->journal->commit(1, $draft['id'], $review['approval']);

        self::assertSame(2, $this->db->fetchOne('SELECT plays FROM movie_user_watch_dates'));
    }

    public function testStaleCanonicalPreviewCannotWrite() : void
    {
        $draft = $this->journal->preview(1, 'Heat (1995) | Loved');
        $review = $this->journal->review(1, $draft['id'], []);
        $this->store->write(1, 'WATCH_HISTORY.md', $this->store->read(1, 'WATCH_HISTORY.md') . "\nNew context\n");
        $this->expectException(InvalidArgumentException::class);

        $this->journal->commit(1, $draft['id'], $review['approval']);
    }

    public function testMissingHumanReviewTokenCannotWrite() : void
    {
        $draft = $this->journal->preview(1, 'Heat (1995)');
        $this->expectException(InvalidArgumentException::class);

        $this->journal->commit(1, $draft['id'], 'invented-token');
    }

    public function testInterruptedFileWriteResumesWithoutAnotherPlay() : void
    {
        $draft = $this->journal->preview(1, 'Heat (1995) | Loved');
        $review = $this->journal->review(1, $draft['id'], []);
        $this->store->failCanonical = true;
        try {
            $this->journal->commit(1, $draft['id'], $review['approval']);
            self::fail('Expected the simulated interruption');
        } catch (\RuntimeException $e) {
            self::assertSame('Simulated interrupted canonical write', $e->getMessage());
        }
        $result = $this->journal->commit(1, $draft['id'], $review['approval']);

        self::assertTrue($result['verified']);
        self::assertSame(1, $this->db->fetchOne('SELECT plays FROM movie_user_watch_dates'));
        self::assertSame('Loved', $this->journal->context(1)['films'][0]['reaction']);
    }
}
