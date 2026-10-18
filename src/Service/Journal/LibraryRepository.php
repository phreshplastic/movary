<?php declare(strict_types=1);

namespace Movary\Service\Journal;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;

class LibraryRepository
{
    public function __construct(public readonly Connection $db)
    {
    }

    public function ready(int $userId) : bool
    {
        return $this->db->createSchemaManager()->tablesExist(['journal_collection'])
            && $this->db->fetchOne('SELECT revision FROM journal_collection WHERE user_id = ?', [$userId]) !== false;
    }

    public function revision(int $userId) : int
    {
        $value = $this->db->fetchOne('SELECT revision FROM journal_collection WHERE user_id = ?', [$userId]);
        if ($value === false) {
            throw new InvalidArgumentException('The personal library has not been migrated yet.');
        }

        return (int)$value;
    }

    /** @return array<string,mixed> */
    public function title(int $userId, string $id) : array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM journal_title WHERE user_id = ? AND id = ?', [$userId, $id]);
        if ($row === false) {
            throw new InvalidArgumentException('That title is not in this library.');
        }

        return $this->decode($row);
    }

    /** @return array<string,mixed>|null */
    public function identity(int $userId, string $type, int $tmdbId) : ?array
    {
        $id = $this->db->fetchOne('SELECT id FROM journal_title WHERE user_id = ? AND media_type = ? AND tmdb_id = ?', [$userId, $type, $tmdbId]);

        return $id === false ? null : $this->title($userId, (string)$id);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function search(int $userId, array $filters = []) : array
    {
        [$where, $params] = $this->where($userId, $filters);
        $limit = max(1, min(100, (int)($filters['limit'] ?? 25)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $sort = match ($filters['sort'] ?? 'title') {
            'year' => 't.release_year DESC, t.title, t.id',
            'recent' => 't.updated_at DESC, t.title, t.id',
            default => 't.title, t.release_year, t.id',
        };
        $total = (int)$this->db->fetchOne('SELECT COUNT(*) FROM journal_title t WHERE ' . $where, $params);
        $rows = $this->db->fetchAllAssociative('SELECT t.* FROM journal_title t WHERE ' . $where . ' ORDER BY ' . $sort . ' LIMIT ' . $limit . ' OFFSET ' . $offset, $params);

        return ['contractVersion' => '1.0', 'revision' => $this->revision($userId), 'total' => $total, 'limit' => $limit,
            'offset' => $offset, 'nextOffset' => $offset + $limit < $total ? $offset + $limit : null,
            'items' => array_map($this->decode(...), $rows)];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{string,list<mixed>}
     */
    private function where(int $userId, array $filters) : array
    {
        $clauses = ['t.user_id = ?'];
        $params = [$userId];
        foreach (['mediaType' => 'media_type', 'status' => 'viewing_status', 'interest' => 'interest', 'reaction' => 'reaction', 'year' => 'release_year'] as $key => $column) {
            if (($filters[$key] ?? '') !== '' && $filters[$key] !== 'all') {
                $clauses[] = 't.' . $column . ' = ?';
                $params[] = $filters[$key];
            }
        }
        foreach (['genre', 'person', 'language', 'country', 'keyword'] as $kind) {
            if (($filters[$kind] ?? '') !== '') {
                $clauses[] = 'EXISTS (SELECT 1 FROM journal_facet f WHERE f.title_id = t.id AND f.kind = ? AND (f.provider_id = ? OR f.label = ?))';
                array_push($params, $kind, $filters[$kind], $filters[$kind]);
            }
        }
        if (trim((string)($filters['query'] ?? '')) !== '') {
            $clauses[] = "(LOWER(t.title) LIKE ? OR LOWER(t.aliases) LIKE ? OR LOWER(t.reaction) LIKE ? OR LOWER(t.note) LIKE ? OR EXISTS (SELECT 1 FROM journal_facet f WHERE f.title_id = t.id AND LOWER(f.label) LIKE ?) OR EXISTS (SELECT 1 FROM journal_event e WHERE e.title_id = t.id AND (LOWER(e.note) LIKE ? OR LOWER(e.reaction) LIKE ?)))";
            for ($i = 0; $i < 7; ++$i) {
                $params[] = '%' . mb_strtolower((string)$filters['query']) . '%';
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    /** @return array<string,mixed> */
    public function history(int $userId, string $id, int $limit = 25, int $offset = 0) : array
    {
        $this->title($userId, $id);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $total = (int)$this->db->fetchOne('SELECT COUNT(*) FROM journal_event WHERE user_id = ? AND title_id = ?', [$userId, $id]);
        $rows = $this->db->fetchAllAssociative('SELECT * FROM journal_event WHERE user_id = ? AND title_id = ? ORDER BY personal_revision DESC, recorded_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, [$userId, $id]);
        foreach ($rows as &$row) {
            $row['provenance'] = json_decode($row['provenance'], true, flags: JSON_THROW_ON_ERROR);
        }

        return ['contractVersion' => '1.0', 'revision' => $this->revision($userId), 'items' => $rows, 'total' => $total,
            'limit' => $limit, 'offset' => $offset, 'nextOffset' => $offset + $limit < $total ? $offset + $limit : null];
    }

    /** Latest applicable event per scope; repeated season updates cannot hide overall progress.
     * @return list<array<string,mixed>>
     */
    public function scopedEvents(int $userId, string $id, bool $progress) : array
    {
        $predicate = $progress ? "kind = 'progress'" : "season IS NOT NULL AND (kind = 'assessment' OR reaction <> '')";
        $rows = $this->db->fetchAllAssociative('SELECT e.* FROM journal_event e WHERE e.user_id = ? AND e.title_id = ? AND e.id IN (SELECT candidate.id FROM journal_event candidate WHERE candidate.user_id = ? AND candidate.title_id = ? AND ' . $predicate . ') AND NOT EXISTS (SELECT 1 FROM journal_event newer WHERE newer.user_id = e.user_id AND newer.title_id = e.title_id AND (newer.season = e.season OR (newer.season IS NULL AND e.season IS NULL)) AND newer.scope = e.scope AND newer.personal_revision > e.personal_revision AND newer.id IN (SELECT applicable.id FROM journal_event applicable WHERE applicable.user_id = ? AND applicable.title_id = ? AND ' . $predicate . ')) ORDER BY e.scope, e.season LIMIT 101', [$userId, $id, $userId, $id, $userId, $id]);
        foreach ($rows as &$row) {
            $row['provenance'] = json_decode($row['provenance'], true, flags: JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function taste(int $userId, ?int $limit = null, int $offset = 0) : array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM journal_taste WHERE user_id = ? ORDER BY kind, id' . ($limit === null ? '' : ' LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset)), [$userId]);
        foreach ($rows as &$row) {
            $row['evidence'] = json_decode($row['evidence'], true, flags: JSON_THROW_ON_ERROR);
            $row['provenance'] = json_decode($row['provenance'], true, flags: JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function facets(int $userId) : array
    {
        $facets = $this->db->fetchAllAssociative('SELECT DISTINCT f.kind, f.provider_id, f.label, f.role FROM journal_facet f JOIN journal_title t ON t.id = f.title_id WHERE t.user_id = ? ORDER BY f.label', [$userId]);
        foreach ($this->db->fetchFirstColumn("SELECT DISTINCT reaction FROM journal_title WHERE user_id = ? AND reaction <> '' ORDER BY reaction", [$userId]) as $reaction) {
            $facets[] = ['kind' => 'reaction', 'provider_id' => $reaction, 'label' => $reaction, 'role' => ''];
        }

        return $facets;
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function decode(array $row) : array
    {
        foreach (['aliases', 'metadata', 'provenance'] as $field) {
            $row[$field] = json_decode($row[$field], true, flags: JSON_THROW_ON_ERROR);
        }
        $row['version'] = (int)$row['version'];
        $row['tmdb_id'] = $row['tmdb_id'] === null ? null : (int)$row['tmdb_id'];
        $row['missingMetadata'] = $row['refreshed_at'] === null;

        return $row;
    }
}
