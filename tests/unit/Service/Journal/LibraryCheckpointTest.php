<?php declare(strict_types=1);

namespace Tests\Unit\Movary\Service\Journal;

use Doctrine\DBAL\DriverManager;
use Movary\Service\Journal\LibraryCheckpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LibraryCheckpoint::class)]
class LibraryCheckpointTest extends TestCase
{
    public function testOnlineCheckpointPreservesTheDatabaseAndUsesPrivatePermissions() : void
    {
        $root = sys_get_temp_dir() . '/library-checkpoint-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $root . '/source.sqlite']);
        $db->executeStatement('CREATE TABLE fixture (reaction TEXT)');
        $db->insert('fixture', ['reaction' => 'Enjoyed']);
        try {
            $checkpoint = (new LibraryCheckpoint($db, new InterruptibleJournalStore($root)))->create(1, 'review-fixture');
            $copy = new \SQLite3($root . '/' . $checkpoint, SQLITE3_OPEN_READONLY);
            self::assertSame('Enjoyed', $copy->querySingle('SELECT reaction FROM fixture'));
            self::assertSame('ok', $copy->querySingle('PRAGMA quick_check'));
            self::assertSame(0600, fileperms($root . '/' . $checkpoint) & 0777);
            $copy->close();
        } finally {
            $db->close();
            foreach (glob($root . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }
}
