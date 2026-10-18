<?php declare(strict_types=1);

namespace Movary\Service\Journal;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Movary\Api\Tmdb\TmdbApi;
use Movary\Domain\Movie\MovieApi;
use Movary\Service\Tmdb\SyncMovie;
use Movary\ValueObject\Config;
use Movary\ValueObject\Date;
use Movary\ValueObject\Year;
use RuntimeException;
use Throwable;

/**
 * @phpstan-import-type Film from JournalDocument
 * @psalm-import-type Film from JournalDocument
 */
class JournalService
{
    public function __construct(
        private readonly JournalDocument $document,
        private readonly JournalStore $store,
        private readonly TmdbApi $tmdb,
        private readonly MovieApi $movies,
        private readonly SyncMovie $syncMovie,
        private readonly Connection $db,
        private readonly Config $config,
        private readonly ?PersonalLibrary $library = null,
        private readonly ?LibraryCompatibility $compatibility = null,
    ) {
    }

    /** @return array{version:string,films:list<Film>,shows:list<Film>,taste:string} */
    public function context(int $userId) : array
    {
        $compatibility = $this->activeLibrary($userId);
        if ($compatibility !== null) {
            return $compatibility->context($userId);
        }
        $markdown = $this->store->read($userId, 'WATCH_HISTORY.md');

        return ['version' => hash('sha256', $markdown), 'films' => $this->document->read($markdown),
            'shows' => $this->document->readShows($markdown), 'taste' => $this->store->read($userId, 'TASTE_PROFILE.md')];
    }

    /** @return array<string,mixed> */
    public function preview(int $userId, string $text, string $mediaType = 'movie') : array
    {
        $compatibility = $this->activeLibrary($userId);
        if ($compatibility !== null) {
            return $compatibility->preview($userId, $text, $mediaType);
        }
        if (in_array($mediaType, ['movie', 'tv'], true) === false) {
            throw new InvalidArgumentException('Choose Movies or TV shows.');
        }
        $markdown = $this->store->read($userId, 'WATCH_HISTORY.md');
        $items = [];
        foreach ($this->document->parse($text) as $film) {
            $candidates = $this->search($film['title'], $film['year'], $mediaType);
            $exact = array_values(array_filter($candidates, fn(array $c) : bool =>
                $film['year'] !== '' && $c['year'] === $film['year']
                && $this->document->normalize($c['title']) === $this->document->normalize($film['title'])));
            $items[] = ['input' => $film, 'candidates' => $candidates,
                'selected' => count($exact) === 1 ? $exact[0]['id'] : null];
        }
        $id = bin2hex(random_bytes(16));
        $draft = ['id' => $id, 'expires' => time() + 7200, 'base' => hash('sha256', $markdown), 'mediaType' => $mediaType, 'items' => $items];
        $this->store->saveDraft($userId, $id, $draft);

        return $draft;
    }

    /**
     * @param array<array-key,mixed> $choices
     * @param array<array-key,mixed> $details
     * @return array<string,mixed>
     */
    public function review(int $userId, string $id, array $choices, array $details = []) : array
    {
        $compatibility = $this->activeLibrary($userId);
        if ($compatibility !== null) {
            return $compatibility->review($userId, $id, $choices, $details);
        }
        return $this->legacyReview($userId, $id, $choices, $details);
    }

    /** @param array<array-key,mixed> $choices
     * @param array<array-key,mixed> $details
     * @return array<string,mixed>
     */
    private function legacyReview(int $userId, string $id, array $choices, array $details) : array
    {
        $draft = $this->store->draft($userId, $id);
        $markdown = $this->store->read($userId, 'WATCH_HISTORY.md');
        if ($draft['base'] !== hash('sha256', $markdown)) {
            throw new InvalidArgumentException('The journal changed. Start a fresh review.');
        }
        $draft += ['mediaType' => 'movie'];
        $mediaType = $draft['mediaType'];
        $films = $mediaType === 'tv' ? $this->document->readShows($markdown) : $this->document->read($markdown);
        $changes = [];
        $selected = [];
        foreach ($draft['items'] as $i => $item) {
            $choice = $choices[(string)$i] ?? $item['selected'];
            if ($choice === 'skip') {
                continue;
            }
            $candidate = $this->candidate($item['candidates'], (int)$choice);
            if (in_array($candidate['id'], $selected, true)) {
                throw new InvalidArgumentException('Two lines select the same title. Keep only one.');
            }
            $selected[] = $candidate['id'];
            $film = $item['input'];
            $film['title'] = $candidate['title'];
            $film['year'] = $candidate['year'];
            $film = $this->document->withDetails($film, (array)($details[(string)$i] ?? []));
            $merged = $this->document->merge($films, $film);
            $films = $merged['films'];
            $action = $mediaType === 'tv' ? 'library-only' : $this->playAction($userId, $candidate['id'], $merged['after']['date']);
            $changes[] = ['before' => $merged['before'], 'after' => $merged['after'],
                'tmdbId' => $candidate['id'], 'mediaType' => $mediaType, 'play' => $action];
        }
        if ($changes === []) {
            throw new InvalidArgumentException('Choose at least one confirmed title.');
        }
        $plan = ['id' => $id, 'base' => $draft['base'], 'changes' => $changes,
            'markdown' => $mediaType === 'tv' ? $this->document->renderShows($markdown, $films) : $this->document->render($markdown, $films), 'playedVersion' => $this->playedVersion($userId)];
        $draft['plan'] = $plan;
        $draft['approval'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
        $this->store->saveDraft($userId, $id, $draft);

        return ['id' => $id, 'approval' => $draft['approval'], 'changes' => $changes];
    }

    /** @return array<string,mixed> */
    public function commit(int $userId, string $id, string $approval) : array
    {
        $compatibility = $this->activeLibrary($userId);
        if ($compatibility !== null) {
            return $compatibility->commit($userId, $id, $approval);
        }
        return $this->store->locked($userId, function () use ($userId, $id, $approval) : array {
            $draft = $this->store->draft($userId, $id);
            if (isset($draft['approval']) === false || hash_equals($draft['approval'], $approval) === false) {
                throw new InvalidArgumentException('Review the changes before saving them.');
            }
            if (isset($draft['receipt'])) {
                return $draft['receipt'];
            }
            $plan = $draft['plan'];
            $before = $this->store->read($userId, 'WATCH_HISTORY.md');
            if ($this->canRecover($userId, $draft, $before)) {
                return $this->finish($userId, $id, $draft);
            }
            if ($plan['base'] !== hash('sha256', $before) || $plan['playedVersion'] !== $this->playedVersion($userId)) {
                throw new InvalidArgumentException('The journal or Movary changed. Review the batch again before saving.');
            }
            $backup = $this->checkpoint($userId, $id, $before);
            $draft['backup'] = $backup;
            // Resolve metadata before opening the play transaction; it may call TMDB.
            $movieIds = [];
            foreach ($plan['changes'] as $change) {
                if ($change['play'] === 'library-only') {
                    continue;
                }
                $movie = $this->movies->findByTmdbId($change['tmdbId']) ?? $this->syncMovie->syncMovie($change['tmdbId']);
                $movieIds[$change['tmdbId']] = $movie->getId();
            }
            $draft = $this->savePlays($userId, $id, $draft, $movieIds);
            return $this->finish($userId, $id, $draft);
        });
    }

    /** @param array<string,mixed> $draft */
    private function canRecover(int $userId, array $draft, string $markdown) : bool
    {
        if (isset($draft['recoverableMarkdown']) === false || in_array(hash('sha256', $markdown),
            [$draft['plan']['base'], hash('sha256', $draft['plan']['markdown'])], true) === false) {
            return false;
        }
        foreach ($draft['plan']['changes'] as $change) {
            if ($change['play'] !== 'library-only' && $this->playAction($userId, $change['tmdbId'], $change['after']['date']) !== 'already-watched') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $draft
     * @param array<int,int> $movieIds
     * @return array<string,mixed>
     */
    private function savePlays(int $userId, string $id, array $draft, array $movieIds) : array
    {
        $plan = $draft['plan'];
        $this->db->beginTransaction();
        try {
            if ($plan['playedVersion'] !== $this->playedVersion($userId)) {
                throw new InvalidArgumentException('A play changed while saving. Review this batch again.');
            }
            foreach ($plan['changes'] as $change) {
                if ($change['play'] !== 'add') {
                    continue;
                }
                $watched = $change['after']['date'];
                $this->movies->addPlaysForMovieOnDate($movieIds[$change['tmdbId']], $userId,
                    $watched === '' ? null : Date::createFromString($watched), postToMastodon: false);
            }
            foreach ($plan['changes'] as $change) {
                if ($change['play'] !== 'library-only' && $this->playAction($userId, $change['tmdbId'], $change['after']['date']) !== 'already-watched') {
                    throw new RuntimeException('Play readback did not match the reviewed change.');
                }
                if ($change['play'] === 'add') {
                    $this->verifyNewPlay($userId, $movieIds[$change['tmdbId']], $change['after']['date']);
                }
            }
            // Preserve the approved text before DB commit so an interrupted save can resume.
            $draft['recoverableMarkdown'] = $plan['markdown'];
            $this->store->saveDraft($userId, $id, $draft);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $draft;
    }

    private function verifyNewPlay(int $userId, int $movieId, string $date) : void
    {
        $plays = $this->db->fetchAllAssociative('SELECT watched_at, plays FROM movie_user_watch_dates WHERE user_id = ? AND movie_id = ?', [$userId, $movieId]);
        $expected = $date === '' ? null : $date;
        if (count($plays) !== 1 || (int)$plays[0]['plays'] !== 1 || $plays[0]['watched_at'] !== $expected) {
            throw new RuntimeException('New-play readback did not match exactly one reviewed watch.');
        }
    }

    /**
     * @param array<string,mixed> $draft
     * @return array<string,mixed>
     */
    private function finish(int $userId, string $id, array $draft) : array
    {
        $this->store->write($userId, 'WATCH_HISTORY.md', $draft['plan']['markdown']);
        $receipt = ['id' => $id, 'saved' => count($draft['plan']['changes']), 'backup' => $draft['backup'],
            'version' => hash('sha256', $draft['plan']['markdown']), 'verified' => true];
        $draft['receipt'] = $receipt;
        $this->store->saveDraft($userId, $id, $draft);

        return $receipt;
    }

    public function export(int $userId, string $format) : string
    {
        $compatibility = $this->activeLibrary($userId);
        if ($compatibility !== null) {
            return $compatibility->export($userId, $format);
        }
        $markdown = $this->store->read($userId, 'WATCH_HISTORY.md');
        if ($format === 'context') {
            return "# Private watched library context\n\nOnly Seen confirms viewing. Preserve reaction words; blank dates are unknown.\n\n"
                . $this->store->read($userId, 'TASTE_PROFILE.md') . "\n\n" . $markdown;
        }
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Unable to create export.');
        }
        fputcsv($stream, ['Type', 'Title', 'Year', 'Status', 'Reaction', 'Watch date', 'Evidence / note'], escape: '');
        foreach (['movie' => $this->document->read($markdown), 'tv' => $this->document->readShows($markdown)] as $type => $entries) {
            foreach ($entries as $entry) {
                fputcsv($stream, [$type, ...array_values($entry)], escape: '');
            }
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }

    /** @return list<array{id:int,title:string,year:string,overview:string,language:string}> */
    private function search(string $title, string $year, string $mediaType) : array
    {
        $candidates = [];
        $page = 1;
        do {
            $releaseYear = $year === '' ? null : Year::createFromString($year);
            $response = $mediaType === 'tv' ? $this->tmdb->searchTv($title, $releaseYear, $page) : $this->tmdb->searchMovie($title, $releaseYear, $page);
            foreach ($response['results'] as $movie) {
                $candidates[] = $this->describeMatch($movie, $mediaType);
            }
            ++$page;
            if ($page > 20 && $page <= ($response['total_pages'] ?? 1)) {
                throw new InvalidArgumentException('Too many matches for ' . $title . '. Add a year to narrow the search.');
            }
        } while ($page <= ($response['total_pages'] ?? 1));

        return $candidates;
    }

    /**
     * @param array<string,mixed> $movie
     * @return array{id:int,title:string,year:string,overview:string,language:string}
     */
    private function describeMatch(array $movie, string $mediaType) : array
    {
        return ['id' => (int)$movie['id'], 'title' => (string)($mediaType === 'tv' ? $movie['name'] : $movie['title']),
            'year' => substr((string)($movie[$mediaType === 'tv' ? 'first_air_date' : 'release_date'] ?? ''), 0, 4),
            'overview' => (string)($movie['overview'] ?? ''), 'language' => (string)($movie['original_language'] ?? '')];
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    private function candidate(array $candidates, int $id) : array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['id'] === $id) {
                return $candidate;
            }
        }
        throw new InvalidArgumentException('Choose the correct version for every title, or skip the line.');
    }

    private function playAction(int $userId, int $tmdbId, string $date) : string
    {
        $plays = $this->db->fetchAllAssociative('SELECT w.watched_at, w.plays FROM movie_user_watch_dates w JOIN movie m ON m.id = w.movie_id WHERE w.user_id = ? AND m.tmdb_id = ?', [$userId, $tmdbId]);
        if ($plays === []) {
            return 'add';
        }
        foreach ($plays as $play) {
            if ((int)$play['plays'] >= 1 && ($date === '' || $play['watched_at'] === $date)) {
                return 'already-watched';
            }
        }
        throw new InvalidArgumentException('A different watch date already exists. Resolve it before adding this movie.');
    }

    private function playedVersion(int $userId) : string
    {
        return hash('sha256', json_encode($this->db->fetchAllAssociative(
            'SELECT movie_id, watched_at, plays FROM movie_user_watch_dates WHERE user_id = ? ORDER BY movie_id, watched_at', [$userId]), JSON_THROW_ON_ERROR));
    }

    private function checkpoint(int $userId, string $id, string $markdown) : string
    {
        if ($this->config->getAsString('DATABASE_MODE', 'sqlite') !== 'sqlite') {
            throw new RuntimeException('Journal checkpoint backups currently require SQLite. Nothing was saved.');
        }
        $name = 'checkpoint-' . $id;
        $this->store->write($userId, $name . '.md', $markdown);
        $path = dirname(__DIR__, 3) . '/' . $this->config->getAsString('DATABASE_SQLITE', 'storage/movary.sqlite');
        $source = new \SQLite3($path, SQLITE3_OPEN_READONLY);
        $targetPath = $this->store->directory($userId) . '/' . $name . '.sqlite';
        $target = new \SQLite3($targetPath);
        try {
            if ($source->backup($target) === false) {
                throw new RuntimeException('Backup failed. Nothing was saved.');
            }
        } finally {
            $source->close();
            $target->close();
        }
        chmod($targetPath, 0600);

        return $name;
    }
    private function activeLibrary(int $userId) : ?LibraryCompatibility
    {
        return $this->library?->ready($userId) === true ? $this->compatibility : null;
    }
}
