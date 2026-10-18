<?php declare(strict_types=1);

namespace Movary\Service\Journal;

use InvalidArgumentException;
use Throwable;

/** A private STDIO adapter; SSH/OS process access is the authentication boundary. */
class JournalMcpServer
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly ?PersonalLibrary $library = null,
    ) {
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>|null
     */
    public function handle(int $userId, bool $writeEnabled, array $request) : ?array
    {
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? '';
        if (is_string($method) === false) {
            return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'A JSON-RPC method must be a string.']];
        }
        if (array_key_exists('id', $request) === false) {
            return null;
        }
        try {
            $result = $this->dispatch($userId, $writeEnabled, $method, (array)($request['params'] ?? []));

            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (InvalidArgumentException $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => $e->getMessage()]];
        } catch (Throwable) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'The journal operation could not finish. No credentials or internal error details are returned. Review the current context before retrying a write.']];
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|object
     */
    private function dispatch(int $userId, bool $writeEnabled, string $method, array $params) : array|object
    {
        return match ($method) {
                'initialize' => ['protocolVersion' => '2025-11-25', 'capabilities' => ['tools' => (object)[]],
                    'serverInfo' => ['name' => 'watched-library', 'version' => '1.0.0'],
                    'instructions' => 'Only canonical Seen rows prove viewing. Preserve reactions and unknown dates. Preview and review all writes, obtain the human approval of those changes, then commit the exact approval token. Never infer a watch from a recommendation or search result.'],
                'ping' => (object)[],
                'tools/list' => ['tools' => $this->tools($writeEnabled)],
                'tools/call' => $this->call($userId, $writeEnabled, $params),
                default => throw new InvalidArgumentException('Unknown MCP method.'),
        };
    }

    /** @return list<array<string,mixed>> */
    private function tools(bool $writeEnabled) : array
    {
        $tools = [['name' => 'library_context', 'description' => 'Read the watched library: films and TV shows, exact reactions, notes, dates, and durable taste. TV Seen means watched, not completion of every episode. Only Seen is confirmed viewing.',
            'inputSchema' => ['type' => 'object', 'properties' => (object)[], 'additionalProperties' => false],
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]]];
        if ($this->library !== null) {
            $tools = [...$this->libraryTools($writeEnabled), ...$tools];
        }
        if ($writeEnabled === false) {
            return $tools;
        }
        foreach ([
            ['preview_watched_titles', 'Prepare a draft from explicitly confirmed watched titles. Set mediaType=tv for TV shows; default movie. Use one title per line with optional (year) | reaction | YYYY-MM-DD | note. This searches metadata and changes no canonical watches.', ['text' => ['type' => 'string'], 'mediaType' => ['type' => 'string', 'enum' => ['movie', 'tv'], 'default' => 'movie']], ['text']],
            ['review_watched_titles', 'Select title versions by candidate TMDB ID (or skip) keyed by zero-based draft row. Return exact canonical and Movary changes for human review. Do not commit until the human approves these changes.', ['id' => ['type' => 'string'], 'choices' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'integer']]], 'details' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'properties' => ['reaction' => ['type' => 'string'], 'date' => ['type' => 'string'], 'note' => ['type' => 'string']], 'additionalProperties' => false]]], ['id', 'choices']],
            ['commit_watched_titles', 'Commit an exact reviewed batch only after human approval. Creates a recovery checkpoint, preserves unknown dates and words, verifies plays, and returns an idempotent receipt.', ['id' => ['type' => 'string'], 'approval' => ['type' => 'string']], ['id', 'approval']],
        ] as [$name, $description, $properties, $required]) {
            $tools[] = ['name' => $name, 'description' => $description,
                'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => $name === 'commit_watched_titles', 'openWorldHint' => $name === 'preview_watched_titles']];
        }

        return $tools;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function call(int $userId, bool $writeEnabled, array $params) : array
    {
        $name = (string)($params['name'] ?? '');
        $arguments = (array)($params['arguments'] ?? []);
        if (in_array($name, ['movie_context', 'library_context', 'get_context', 'search_library', 'get_title', 'get_viewing_history', 'search_catalog'], true) === false && $writeEnabled === false) {
            throw new InvalidArgumentException('This connection has read-only access.');
        }
        try {
            $result = $this->invoke($userId, $name, $arguments);

            return ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]], 'isError' => false];
        } catch (InvalidArgumentException $e) {
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        }
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function invoke(int $userId, string $name, array $arguments) : array
    {
        if (in_array($name, ['get_context', 'search_library', 'get_title', 'get_viewing_history', 'search_catalog', 'preview_changes', 'commit_changes'], true)) {
            return $this->invokeLibrary($userId, $name, $arguments);
        }
        $arguments += ['text' => '', 'mediaType' => 'movie', 'id' => '', 'choices' => [], 'details' => [], 'approval' => ''];
        $arguments += ['id' => '', 'creditsOffset' => 0, 'creditsLimit' => 25, 'limit' => 25, 'offset' => 0, 'query' => '', 'mediaType' => 'all', 'page' => 1, 'changes' => [], 'requestId' => null, 'approval' => '', 'tasteOffset' => 0, 'fullStatements' => false];
        return match ($name) {
            'movie_context', 'library_context' => $this->journal->context($userId),
            'preview_watched_movies', 'preview_watched_titles' => $this->journal->preview($userId, (string)$arguments['text'], (string)$arguments['mediaType']),
            'review_watched_movies', 'review_watched_titles' => $this->journal->review($userId, (string)$arguments['id'], (array)$arguments['choices'], (array)$arguments['details']),
            'commit_watched_movies', 'commit_watched_titles' => $this->journal->commit($userId, (string)$arguments['id'], (string)$arguments['approval']),
            default => throw new InvalidArgumentException('Unknown journal tool.'),
        };
    }

    /** @return list<array<string,mixed>> */
    private function libraryTools(bool $writeEnabled) : array
    {
        $string = ['type' => 'string'];
        $integer = ['type' => 'integer'];
        $tools = [];
        foreach ([
            ['get_context', 'Start here: compact attributed taste, collection coverage, personal revision, and retrieval guidance. Imported context is not newly approved model insight. Taste is paginated 25 at a time; use fullStatements to read the complete text of a page.', ['tasteOffset' => $integer, 'fullStatements' => ['type' => 'boolean']], []],
            ['search_library', 'Search personal records, notes, reactions, genres, credited people, languages, countries, keywords. Status seen/unseen/unknown; interest none/want/not_interested. Default 25, maximum 100. Provider facts do not prove viewing.', ['query' => $string, 'mediaType' => ['type' => 'string', 'enum' => ['movie', 'tv', 'all']], 'status' => ['type' => 'string', 'enum' => ['seen', 'unseen', 'unknown', 'all']], 'interest' => $string, 'reaction' => $string, 'genre' => $string, 'person' => $string, 'language' => $string, 'country' => $string, 'keyword' => $string, 'year' => $string, 'sort' => ['type' => 'string', 'enum' => ['title', 'year', 'recent']], 'limit' => $integer, 'offset' => $integer], []],
            ['get_title', 'Retrieve rich provider facts, exact personal words, provenance, missing metadata, scoped TV progress, and a page of credits. Fetch further credits with creditsOffset.', ['id' => $string, 'creditsOffset' => $integer, 'creditsLimit' => $integer], ['id']],
            ['get_viewing_history', 'Paginated viewing evidence, actual logged viewings, changing assessments, and scoped TV progress. Historical evidence does not establish a lifetime count.', ['id' => $string, 'limit' => $integer, 'offset' => $integer], ['id']],
            ['search_catalog', 'Search movie and TV metadata without creating personal records. Unknown catalog status is not explicitly unseen. Select a provider identity before proposing a change.', ['query' => $string, 'mediaType' => ['type' => 'string', 'enum' => ['all', 'movie', 'tv']], 'page' => $integer], ['query']],
        ] as [$name, $description, $properties, $required]) {
            $tools[] = ['name' => $name, 'description' => $description, 'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => $name === 'search_catalog']];
        }
        if ($writeEnabled) {
            $change = ['type' => 'object', 'properties' => [
                'action' => ['type' => 'string', 'enum' => ['log_watch', 'edit_assessment', 'confirm_seen', 'set_interest', 'set_status', 'set_progress', 'resolve_identity', 'save_taste']],
                'titleId' => $string, 'mediaType' => ['type' => 'string', 'enum' => ['movie', 'tv']], 'tmdbId' => $integer,
                'reaction' => $string, 'note' => $string, 'date' => ['type' => ['string', 'null']], 'season' => ['type' => ['integer', 'null']],
                'progress' => ['type' => 'string', 'enum' => ['started', 'in_progress', 'completed', 'stopped']],
                'interest' => ['type' => 'string', 'enum' => ['none', 'want', 'not_interested']], 'status' => ['type' => 'string', 'enum' => ['unseen', 'unknown']],
                'tasteId' => $string, 'kind' => ['type' => 'string', 'enum' => ['explicit', 'approved_insight']], 'statement' => $string,
                'evidence' => ['type' => 'array', 'items' => $string, 'maxItems' => 100]], 'required' => ['action'], 'additionalProperties' => false];
            $tools[] = ['name' => 'preview_changes', 'description' => 'Propose exact personal changes for human review. No personal write occurs. log_watch creates another viewing; edit_assessment changes an assessment without a viewing. confirm_seen is historical evidence with unknown lifetime count. Dates must be explicit YYYY-MM-DD or null for unknown. Season reactions never replace the overall show reaction. save_taste interpretations require supporting confirmed title IDs and human approval. Preserve temporary constraints in chat. Reuse requestId for transport retries.',
                'inputSchema' => ['type' => 'object', 'properties' => ['changes' => ['type' => 'array', 'items' => $change, 'minItems' => 1, 'maxItems' => 100], 'requestId' => $string], 'required' => ['changes'], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true]];
            $tools[] = ['name' => 'commit_changes', 'description' => 'Only after the human approves the exact preview: commit its id and approval token. Rejects stale or expired proposals; retries return the same durable receipt and never duplicate watches.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => $string, 'approval' => $string], 'required' => ['id', 'approval'], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]];
        }

        return $tools;
    }

    /** @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function invokeLibrary(int $userId, string $name, array $arguments) : array
    {
        if ($this->library === null) {
            throw new InvalidArgumentException('The structured library is unavailable.');
        }
        $arguments += ['id' => '', 'creditsOffset' => 0, 'creditsLimit' => 25, 'limit' => 25, 'offset' => 0, 'query' => '', 'mediaType' => 'all', 'page' => 1, 'changes' => [], 'requestId' => null, 'approval' => '', 'tasteOffset' => 0, 'fullStatements' => false];
        return match ($name) {
            'get_context' => $this->library->context($userId, (int)$arguments['tasteOffset'], (bool)$arguments['fullStatements']),
            'search_library' => $this->library->search($userId, $arguments),
            'get_title' => $this->library->title($userId, (string)$arguments['id'], (int)$arguments['creditsOffset'], (int)$arguments['creditsLimit']),
            'get_viewing_history' => $this->library->history($userId, (string)$arguments['id'], (int)$arguments['limit'], (int)$arguments['offset']),
            'search_catalog' => $this->library->catalog($userId, (string)$arguments['query'], (string)$arguments['mediaType'], (int)$arguments['page']),
            'preview_changes' => $this->library->preview($userId, array_values((array)$arguments['changes']), isset($arguments['requestId']) ? (string)$arguments['requestId'] : null),
            'commit_changes' => $this->library->commit($userId, (string)$arguments['id'], (string)$arguments['approval']),
            default => throw new InvalidArgumentException('Unknown library operation.'),
        };
    }
}
