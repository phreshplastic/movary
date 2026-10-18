<?php declare(strict_types=1);

namespace Movary\DatabaseMigration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\Migrations\AbstractMigration;
use Movary\Service\Journal\LibrarySchema;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add the private personal library, independent of provider metadata and native plays';
    }

    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
    public function up(Schema $schema) : void
    {
        foreach (LibrarySchema::statements() as $sql) {
            $this->addSql($sql);
        }
        foreach (['movie_user_watch_dates', 'movie_user_rating', 'watchlist'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                $row = $event === 'DELETE' ? 'OLD' : 'NEW';
                $trigger = 'journal_guard_' . $table . '_' . strtolower($event);
                if ($this->platform instanceof SqlitePlatform) {
                    $this->addSql("CREATE TRIGGER $trigger BEFORE $event ON $table WHEN EXISTS (SELECT 1 FROM journal_collection WHERE user_id = $row.user_id) BEGIN SELECT RAISE(ABORT, 'Use the personal library reviewed write service'); END");
                } else {
                    $this->addSql("CREATE TRIGGER $trigger BEFORE $event ON $table FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM journal_collection WHERE user_id = $row.user_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Use the personal library reviewed write service'; END IF; END");
                }
            }
        }
    }

    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
    public function down(Schema $schema) : void
    {
        throw new \RuntimeException('Restore a verified backup to reverse a personal-library migration.');
    }
}
