<?php declare(strict_types=1);

namespace Movary\Service\Journal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use RuntimeException;

class LibraryCheckpoint
{
    public function __construct(private readonly Connection $db, private readonly JournalStore $store)
    {
    }

    public function create(int $userId, string $id) : string
    {
        if ($this->db->getDatabasePlatform() instanceof SqlitePlatform === false) {
            throw new RuntimeException('Bulk personal changes require SQLite recovery storage.');
        }
        $database = $this->db->fetchAssociative('PRAGMA database_list');
        $path = $database === false ? null : $database['file'];
        if (is_string($path) === false || $path === '') {
            throw new RuntimeException('Bulk personal changes require a file-backed SQLite recovery checkpoint.');
        }
        $directory = $this->store->directory($userId);
        if (is_dir($directory) === false && mkdir($directory, 0700, true) === false) {
            throw new RuntimeException('Unable to create private recovery storage.');
        }
        $name = 'before-personal-' . $id . '.sqlite';
        $source = new \SQLite3($path, SQLITE3_OPEN_READONLY);
        $target = new \SQLite3($directory . '/' . $name);
        chmod($directory . '/' . $name, 0600);
        try {
            if ($source->backup($target) === false) {
                throw new RuntimeException('The recovery checkpoint could not finish. No personal changes were committed.');
            }
        } finally {
            $source->close();
            $target->close();
        }

        return $name;
    }
}
