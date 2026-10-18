<?php declare(strict_types=1);

namespace Movary\Service\Journal;

use InvalidArgumentException;

/** Older MCP clients and batch intake adapt into the same personal-record transactions. */
class LibraryCompatibility
{
    public function __construct(
        private readonly PersonalLibrary $library,
        private readonly LibraryRepository $repository,
        private readonly JournalStore $store,
        private readonly JournalDocument $document,
    ) {
    }

    /** @return array{version:string,films:list<array{title:string,year:string,status:string,reaction:string,date:string,note:string}>,shows:list<array{title:string,year:string,status:string,reaction:string,date:string,note:string}>,taste:string} */
    public function context(int $userId) : array
    {
        $result = ['version' => (string)$this->repository->revision($userId), 'films' => [], 'shows' => [], 'taste' => ''];
        foreach ($this->repository->db->fetchAllAssociative('SELECT * FROM journal_title WHERE user_id = ? ORDER BY title, release_year', [$userId]) as $row) {
            $date = $this->repository->db->fetchOne('SELECT watched_on FROM journal_event WHERE user_id = ? AND title_id = ? AND watched_on IS NOT NULL ORDER BY watched_on DESC', [$userId, $row['id']]);
            $result[$row['media_type'] === 'tv' ? 'shows' : 'films'][] = ['title' => (string)$row['title'], 'year' => (string)$row['release_year'],
                'status' => match ($row['viewing_status']) { 'seen' => 'Seen', 'unseen' => 'Not seen', default => 'Unconfirmed' },
                'reaction' => (string)$row['reaction'], 'note' => (string)$row['note'], 'date' => $date === false || $date === null ? '' : (string)$date];
        }
        foreach ($this->repository->taste($userId) as $taste) {
            $result['taste'] .= '## ' . $taste['kind'] . "\n\n" . $taste['statement'] . "\n\n";
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function preview(int $userId, string $text, string $type) : array
    {
        $items = [];
        foreach ($this->document->parse($text) as $entry) {
            $found = $this->library->catalog($userId, $entry['title'], $type);
            $candidates = [];
            foreach ($found['items'] as $candidate) {
                $candidates[] = $candidate + ['id' => $candidate['tmdbId']];
            }
            $exact = array_values(array_filter($candidates, fn(array $c) : bool => $entry['year'] !== '' && $c['year'] === $entry['year'] && $this->document->normalize($c['title']) === $this->document->normalize($entry['title'])));
            $items[] = ['input' => $entry, 'candidates' => $candidates, 'selected' => count($exact) === 1 ? $exact[0]['id'] : null];
        }
        $id = bin2hex(random_bytes(16));
        $draft = ['id' => $id, 'expires' => time() + 7200, 'base' => $this->repository->revision($userId), 'mediaType' => $type, 'items' => $items];
        $this->store->saveDraft($userId, $id, $draft);

        return $draft;
    }

    /** @param array<array-key,mixed> $choices
     * @param array<array-key,mixed> $details
     * @return array<string,mixed>
     */
    public function review(int $userId, string $id, array $choices, array $details) : array
    {
        $draft = $this->store->draft($userId, $id);
        if ($draft['base'] !== $this->repository->revision($userId)) {
            throw new InvalidArgumentException('The library changed. Review the batch again.');
        }
        $operations = [];
        foreach ($draft['items'] as $i => $item) {
            $choice = $choices[$i] ?? $item['selected'];
            if ($choice === 'skip') {
                continue;
            }
            $matches = array_values(array_filter($item['candidates'], static fn(array $c) : bool => $c['id'] === (int)$choice));
            if (count($matches) !== 1) {
                throw new InvalidArgumentException('Select a title version or skip it.');
            }
            $candidate = $matches[0];
            $operations[] = $this->operation($userId, $draft['mediaType'], $item['input'], $candidate, (array)($details[$i] ?? []));
        }
        $plan = $this->library->preview($userId, $operations);
        $changes = [];
        foreach ($plan['changes'] as $change) {
            $after = $change['after'];
            $changes[] = ['before' => $this->projectBefore($change['before']), 'after' => ['status' => 'Seen', 'title' => $after['title'], 'year' => $after['release_year'], 'reaction' => $after['reaction'], 'note' => $after['note'], 'date' => $change['event']['watched_on'] ?? ''],
                'tmdbId' => $after['tmdb_id'], 'mediaType' => $after['media_type'], 'play' => 'personal-library-only'];
        }

        $draft['structuredPlanId'] = $plan['id'];
        $this->store->saveDraft($userId, $id, $draft);
        return ['id' => $id, 'approval' => $plan['approval'], 'changes' => $changes];
    }

    /** @param array<string,mixed> $before
     * @return array<string,mixed>|null
     */
    private function projectBefore(array $before) : ?array
    {
        if ($before['version'] === 0) {
            return null;
        }

        return ['title' => $before['title'], 'year' => $before['release_year'],
            'status' => match ($before['viewing_status']) { 'seen' => 'Seen', 'unseen' => 'Not seen', default => 'Unconfirmed' },
            'reaction' => $before['reaction'], 'note' => $before['note']];
    }

    /** @param array<string,mixed> $candidate
     * @param array{title:string,year:string,status:string,reaction:string,date:string,note:string} $entry
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private function operation(int $userId, string $type, array $entry, array $candidate, array $details) : array
    {
        $entry = $this->document->withDetails($entry, $details);
        $existing = $this->repository->identity($userId, $type, $candidate['id']);
        if ($existing === null) {
            $localId = $this->repository->db->fetchOne('SELECT id FROM journal_title WHERE user_id = ? AND media_type = ? AND LOWER(title) = LOWER(?) AND release_year = ?', [$userId, $type, $entry['title'], $entry['year']]);
            $existing = $localId === false ? null : $this->repository->title($userId, (string)$localId);
        }
        return ['action' => 'confirm_seen', 'titleId' => $existing['id'] ?? '', 'mediaType' => $type,
        'tmdbId' => $candidate['id'], 'reaction' => $entry['reaction'] !== '' ? $entry['reaction'] : ($existing['reaction'] ?? ''),
        'date' => $entry['date'] ?: null, 'note' => $entry['note'] !== '' ? $entry['note'] : ($existing['note'] ?? '')];
    }

    /** @return array<string,mixed> */
    public function commit(int $userId, string $id, string $approval) : array
    {
        $draft = $this->store->draft($userId, $id);
        if (isset($draft['structuredPlanId']) === false) {
            throw new InvalidArgumentException('Review a fresh batch before saving.');
        }
        $receipt = $this->library->commit($userId, $draft['structuredPlanId'], $approval) + ['version' => (string)$this->repository->revision($userId), 'backup' => 'transactional audit; full backups are maintained separately'];
        $draft['receipt'] = $receipt;
        $this->store->saveDraft($userId, $id, $draft);
        return $receipt;
    }
    public function export(int $userId, string $format) : string
    {
        return $this->library->export($userId, $format);
    }
}
