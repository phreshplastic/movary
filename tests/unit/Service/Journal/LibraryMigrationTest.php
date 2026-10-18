<?php declare(strict_types=1);

namespace Tests\Unit\Movary\Service\Journal;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Movary\DatabaseMigration\Version20261018120000;
use Movary\Service\Journal\JournalDocument;
use Movary\Service\Journal\LibraryMigration;
use Movary\Service\Journal\LibraryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(LibraryMigration::class)]
#[CoversClass(Version20261018120000::class)]
class LibraryMigrationTest extends TestCase
{
    public function testMigrationPreservesEvidenceAndBlocksCompetingNativeWrites() : void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE movie (tmdb_id INTEGER, title TEXT, release_date TEXT)');
        $db->insert('movie', ['tmdb_id' => 42, 'title' => 'A film', 'release_date' => '1995-01-01']);
        foreach (['movie_user_watch_dates', 'movie_user_rating', 'watchlist'] as $table) {
            $db->executeStatement('CREATE TABLE ' . $table . ' (user_id INTEGER, movie_id INTEGER)');
        }
        require_once dirname(__DIR__, 4) . '/db/migrations/doctrine/Version20261018120000.php';
        $schema = new Version20261018120000($db, new NullLogger());
        $schema->up(new Schema());
        foreach ($schema->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $root = sys_get_temp_dir() . '/library-migration-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $store = new InterruptibleJournalStore($root);
        $history = "# Original evidence\n\n| Film | Year | Status | Reaction | Watch date | Evidence / note |\n| --- | --- | --- | --- | --- | --- |\n| A film | 1995 | Seen | Enjoyed | | Exact words. |\n| Another film | 2001 | Seen | Loved | 2026-10-16 | A known date. |\n| A candidate | 2010 | Not seen | | | Explicitly unseen. |\n| A suggestion | 2020 | Unconfirmed | | | Suggested only. |\n\nFranchise statement needs title-by-title confirmation.\n";
        $taste = "Original taste includes TV favorites; this does not prove TV watches.\n";
        $store->write(1, 'WATCH_HISTORY.md', $history);
        $store->write(1, 'TASTE_PROFILE.md', $taste);
        try {
            $repository = new LibraryRepository($db);
            $migration = new LibraryMigration($repository, $store, new JournalDocument());
            $preview = $migration->preview(1);
            self::assertCount(4, $preview['records']);
            $receipt = $migration->migrate(1, $preview['approval']);
            self::assertSame(4, $receipt['migrated']);
            self::assertSame($history, $store->read(1, 'WATCH_HISTORY.md'));
            self::assertSame($taste, $store->read(1, 'TASTE_PROFILE.md'));
            self::assertSame(2, (int)$db->fetchOne('SELECT COUNT(*) FROM journal_event'));
            self::assertSame(0, (int)$db->fetchOne("SELECT COUNT(*) FROM journal_event WHERE kind <> 'historical_evidence'"));
            self::assertSame(0, (int)$db->fetchOne("SELECT COUNT(*) FROM journal_title WHERE media_type = 'tv'"));
            self::assertSame('Enjoyed', $repository->search(1, ['query' => 'A film'])['items'][0]['reaction']);
            self::assertTrue($migration->migrate(1, $preview['approval'])['alreadyMigrated']);
            self::assertSame(1, $repository->revision(1));
            // Unmigrated users retain upstream behavior; migrated users cannot fork their history.
            $db->insert('movie_user_watch_dates', ['user_id' => 2, 'movie_id' => 42]);
            $this->expectException(\Doctrine\DBAL\Exception::class);
            $db->insert('movie_user_watch_dates', ['user_id' => 1, 'movie_id' => 42]);
        } finally {
            foreach (glob($root . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }
}
