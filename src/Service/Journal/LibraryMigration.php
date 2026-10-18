<?php declare(strict_types=1);

namespace Movary\Service\Journal;

use InvalidArgumentException;

class LibraryMigration
{
    public function __construct(
        private readonly LibraryRepository $repository,
        private readonly JournalStore $store,
        private readonly JournalDocument $document,
    ) {
    }

    /** @return array<string,mixed> */
    public function preview(int $userId) : array
    {
        $history = $this->store->read($userId, 'WATCH_HISTORY.md');
        $taste = $this->store->read($userId, 'TASTE_PROFILE.md');
        $records = [];
        foreach (['movie' => $this->document->read($history), 'tv' => $this->document->readShows($history)] as $type => $entries) {
            foreach ($entries as $entry) {
                // Existing synced metadata establishes identity, never additional viewing evidence.
                $verifiedId = $type === 'movie' ? $this->repository->db->fetchOne('SELECT tmdb_id FROM movie WHERE LOWER(title) = LOWER(?) AND release_date LIKE ?', [$entry['title'], $entry['year'] . '%']) : false;
                $records[] = ['id' => substr(hash('sha256', $userId . ':' . $type . ':' . $entry['title'] . ':' . $entry['year']), 0, 32),
                    'mediaType' => $type, 'entry' => $entry, 'tmdbId' => $verifiedId === false ? null : (int)$verifiedId];
            }
        }
        $payload = ['userId' => $userId, 'records' => $records, 'history' => $history, 'taste' => $taste];

        return $payload + ['approval' => hash('sha256', PersonalLibrary::json($payload)),
            'warning' => 'Seen rows become historical evidence, not exactly one lifetime viewing. No native plays are created. Imported taste remains attributed legacy context, not newly approved model insight.'];
    }

    /** @return array<string,mixed> */
    public function migrate(int $userId, string $approval) : array
    {
        $plan = $this->preview($userId);
        if (hash_equals($plan['approval'], $approval) === false) {
            throw new InvalidArgumentException('The migration evidence changed. Review the migration again.');
        }
        $evidence = 'migration-evidence-' . $approval . '.json';
        $this->store->write($userId, $evidence, PersonalLibrary::json($plan));
        return $this->repository->db->transactional(function () use ($userId, $plan, $evidence) : array {
            if ($this->repository->ready($userId)) {
                return ['alreadyMigrated' => true, 'revision' => $this->repository->revision($userId)];
            }
            $db = $this->repository->db;
            $recorded = gmdate('c');
            $db->insert('journal_collection', ['user_id' => $userId, 'revision' => 1, 'source_hash' => $plan['approval'], 'migrated_at' => $recorded]);
            foreach ($plan['records'] as $record) {
                $entry = $record['entry'];
                $provenance = PersonalLibrary::json(['source' => 'canonical_watch_history', 'evidence' => $evidence, 'original' => $entry,
                    'identity' => $record['tmdbId'] === null ? 'unresolved' : 'existing_movary_match']);
                $db->insert('journal_title', ['id' => $record['id'], 'user_id' => $userId, 'media_type' => $record['mediaType'],
                    'tmdb_id' => $record['tmdbId'], 'title' => $entry['title'], 'release_year' => $entry['year'],
                    'aliases' => '[]', 'metadata' => '{}', 'refreshed_at' => null,
                    'viewing_status' => match ($entry['status']) { 'Seen' => 'seen', 'Not seen' => 'unseen', default => 'unknown' },
                    'interest' => 'none', 'reaction' => $entry['reaction'], 'note' => $entry['note'], 'version' => 1, 'updated_at' => $recorded, 'provenance' => $provenance]);
                if ($entry['status'] === 'Seen') {
                    $db->insert('journal_event', ['id' => substr(hash('sha256', $record['id'] . ':evidence'), 0, 32),
                        'user_id' => $userId, 'title_id' => $record['id'], 'kind' => 'historical_evidence',
                        'scope' => $record['mediaType'] === 'tv' ? 'show' : 'movie', 'season' => null,
                        'watched_on' => $entry['date'] === '' ? null : $entry['date'], 'reaction' => $entry['reaction'], 'note' => $entry['note'],
                        'progress' => '', 'provenance' => $provenance, 'recorded_at' => $recorded, 'personal_revision' => 1]);
                }
            }
            $db->insert('journal_taste', ['id' => bin2hex(random_bytes(16)), 'user_id' => $userId, 'kind' => 'imported_context',
                'statement' => $plan['taste'], 'evidence' => '[]',
                'provenance' => PersonalLibrary::json(['source' => 'TASTE_PROFILE.md', 'evidence' => $evidence, 'attribution' => 'Legacy summary contains user statements and interpretations; not newly approved insight.']), 'version' => 1]);
            // Preserve narrative/franchise uncertainty outside the old table, without duplicating records.
            $narrative = preg_replace('/^\|.*$/m', '', $plan['history']);
            $db->insert('journal_taste', ['id' => bin2hex(random_bytes(16)), 'user_id' => $userId, 'kind' => 'imported_context',
                'statement' => $narrative, 'evidence' => '[]', 'provenance' => PersonalLibrary::json(['source' => 'WATCH_HISTORY.md narrative', 'evidence' => $evidence]), 'version' => 1]);
            $db->insert('journal_audit', ['id' => bin2hex(random_bytes(16)), 'user_id' => $userId, 'revision' => 1,
                'change_id' => $plan['approval'], 'before_state' => PersonalLibrary::json(['source' => $evidence]),
                'after_state' => PersonalLibrary::json(['migratedRecords' => count($plan['records']), 'nativePlaysCreated' => 0]), 'recorded_at' => $recorded]);
            return ['migrated' => count($plan['records']), 'revision' => 1, 'evidence' => $evidence, 'nativePlaysCreated' => 0];
        });
    }
}
