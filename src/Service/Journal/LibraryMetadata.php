<?php declare(strict_types=1);

namespace Movary\Service\Journal;

use InvalidArgumentException;
use Movary\Api\Tmdb\TmdbClient;
use Movary\Service\ImageUrlService;
use Throwable;

/** Uses the same configured TMDB client as Movary; never returns provider exception messages. */
class LibraryMetadata
{
    public function __construct(private readonly TmdbClient $client, private readonly ImageUrlService $images)
    {
    }

    public function type(string $type) : string
    {
        if (in_array($type, ['movie', 'tv'], true) === false) {
            throw new InvalidArgumentException('Choose movie or tv.');
        }

        return $type;
    }

    /** @return array<string,mixed> */
    public function search(string $query, string $type = 'all', int $page = 1) : array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 200 || $page < 1 || $page > 500) {
            throw new InvalidArgumentException('Enter a title between 2 and 200 characters; page must be 1–500.');
        }
        $types = $type === 'all' ? ['movie', 'tv'] : [$this->type($type)];
        $items = [];
        $more = false;
        try {
            foreach ($types as $mediaType) {
                $result = $this->client->get('/search/' . $mediaType, ['query' => urlencode($query), 'page' => (string)$page]);
                $more = $more || (int)($result['total_pages'] ?? 0) > $page;
                foreach ($result['results'] ?? [] as $row) {
                    $items[] = $this->candidate($row, $mediaType);
                }
            }
        } catch (Throwable) {
            throw new InvalidArgumentException('Title search is temporarily unavailable. Your library is still available. Try again later.');
        }

        usort($items, static fn(array $a, array $b) : int => $b['popularity'] <=> $a['popularity']);
        return ['contractVersion' => '1.0', 'items' => $items, 'page' => $page, 'nextPage' => $more ? $page + 1 : null,
            'provenance' => ['source' => 'TMDB', 'fetchedAt' => gmdate('c')], 'warning' => 'Catalog matches are not viewing evidence.'];
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function candidate(array $row, string $mediaType) : array
    {
        return ['mediaType' => $mediaType, 'tmdbId' => (int)$row['id'],
            'title' => (string)($row['title'] ?? $row['name'] ?? ''),
            'year' => substr((string)($row['release_date'] ?? $row['first_air_date'] ?? ''), 0, 4),
            'poster' => $this->poster($row['poster_path'] ?? null), 'overview' => (string)($row['overview'] ?? ''),
            'viewingStatus' => 'unknown', 'popularity' => (float)($row['popularity'] ?? 0)];
    }

    /** @return array<string,mixed> */
    public function fetch(string $type, int $id) : array
    {
        $this->type($type);
        if ($id < 1) {
            throw new InvalidArgumentException('Select a valid TMDB identity.');
        }
        try {
            $raw = $this->client->get('/' . $type . '/' . $id, ['append_to_response' => $type === 'tv' ? 'aggregate_credits,keywords,external_ids' : 'credits,keywords,external_ids']);
        } catch (Throwable) {
            throw new InvalidArgumentException('Metadata is temporarily unavailable. Existing personal records have not changed.');
        }
        if ((int)($raw['id'] ?? 0) !== $id) {
            throw new InvalidArgumentException('The provider returned a different identity.');
        }
        return ['tmdbId' => $id, 'mediaType' => $type] + $this->facts($raw, $type);
    }

    /** @param array<string,mixed> $raw
     * @return list<array<string,mixed>>
     */
    private function people(array $raw, string $type) : array
    {
        $credits = $raw[$type === 'tv' ? 'aggregate_credits' : 'credits'] ?? [];
        $people = [];
        foreach ($raw['created_by'] ?? [] as $person) {
            $people[] = ['id' => (int)$person['id'], 'name' => (string)$person['name'], 'role' => 'Creator', 'scope' => 'series'];
        }
        foreach ($credits['cast'] ?? [] as $person) {
            $people[] = ['id' => (int)$person['id'], 'name' => (string)$person['name'], 'role' => 'Cast', 'scope' => $type === 'tv' ? 'aggregate' : 'movie',
                'characters' => $person['roles'] ?? [['character' => $person['character'] ?? '']]];
        }
        return [...$people, ...$this->crew($credits['crew'] ?? [], $type)];
    }

    /** @param list<array<string,mixed>> $crew
     * @return list<array<string,mixed>>
     */
    private function crew(array $crew, string $type) : array
    {
        $people = [];
        foreach ($crew as $person) {
            $jobs = $person['jobs'] ?? [['job' => $person['job'] ?? 'Crew']];
            foreach ($jobs as $job) {
                $people[] = ['id' => (int)$person['id'], 'name' => (string)$person['name'], 'role' => (string)$job['job'], 'scope' => $type === 'tv' ? 'aggregate' : 'movie', 'episodeCount' => $job['episode_count'] ?? null];
            }
        }

        return $people;
    }

    /** @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function facts(array $raw, string $type) : array
    {
        $id = (int)$raw['id'];
        return ['tmdbId' => $id, 'mediaType' => $type, 'title' => (string)($raw[$type === 'tv' ? 'name' : 'title'] ?? ''),
            'year' => substr((string)($raw[$type === 'tv' ? 'first_air_date' : 'release_date'] ?? ''), 0, 4),
            'aliases' => array_values(array_unique(array_filter([$raw[$type === 'tv' ? 'original_name' : 'original_title'] ?? '']))),
        ] + $this->descriptiveFacts($raw) + $this->extraFacts($raw, $type);
    }

    /** @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function descriptiveFacts(array $raw) : array
    {
        return [
            'poster' => $this->poster($raw['poster_path'] ?? null), 'overview' => (string)($raw['overview'] ?? ''),
            'releaseDate' => $raw['release_date'] ?? $raw['first_air_date'] ?? null,
            'language' => (string)($raw['original_language'] ?? ''), 'languages' => $raw['spoken_languages'] ?? [],
        ];
    }

    /** @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function extraFacts(array $raw, string $type) : array
    {
        $id = (int)$raw['id'];
        return [
            'countries' => $raw['production_countries'] ?? [], 'runtime' => $raw['runtime'] ?? $raw['episode_run_time'] ?? null,
            'genres' => $raw['genres'] ?? [], 'keywords' => $raw['keywords']['keywords'] ?? $raw['keywords']['results'] ?? [],
            'people' => $this->people($raw, $type), 'seasons' => $raw['seasons'] ?? [], 'externalIds' => $raw['external_ids'] ?? [],
            'providerRating' => $raw['vote_average'] ?? null,
            'provenance' => ['source' => 'TMDB', 'mediaType' => $type, 'id' => $id, 'fetchedAt' => gmdate('c')]];
    }

    public function poster(?string $path) : ?string
    {
        return $path !== null && preg_match('#^/[a-zA-Z0-9_.-]+$#D', $path) === 1 ? $this->images->generateImageSrcUrlFromParameters($path, null) : null;
    }
}
