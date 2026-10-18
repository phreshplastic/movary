<?php declare(strict_types=1);

namespace Movary\Service\Journal;

use InvalidArgumentException;
use Throwable;

/** A private STDIO adapter; SSH/OS process access is the authentication boundary. */
class JournalMcpServer
{
    public function __construct(
        private readonly JournalService $journal,
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
                    'serverInfo' => ['name' => 'watched-library', 'version' => '0.2.0'],
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
        if (in_array($name, ['movie_context', 'library_context'], true) === false && $writeEnabled === false) {
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
        $arguments += ['text' => '', 'mediaType' => 'movie', 'id' => '', 'choices' => [], 'details' => [], 'approval' => ''];
        return match ($name) {
            'movie_context', 'library_context' => $this->journal->context($userId),
            'preview_watched_movies', 'preview_watched_titles' => $this->journal->preview($userId, (string)$arguments['text'], (string)$arguments['mediaType']),
            'review_watched_movies', 'review_watched_titles' => $this->journal->review($userId, (string)$arguments['id'], (array)$arguments['choices'], (array)$arguments['details']),
            'commit_watched_movies', 'commit_watched_titles' => $this->journal->commit($userId, (string)$arguments['id'], (string)$arguments['approval']),
            default => throw new InvalidArgumentException('Unknown journal tool.'),
        };
    }
}
