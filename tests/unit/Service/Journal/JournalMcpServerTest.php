<?php declare(strict_types=1);

namespace Tests\Unit\Movary\Service\Journal;

use Movary\Service\Journal\JournalMcpServer;
use Movary\Service\Journal\JournalService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(JournalMcpServer::class)]
class JournalMcpServerTest extends TestCase
{
    public function testReadOnlyConnectionDoesNotAdvertiseWrites() : void
    {
        $journal = $this->createStub(JournalService::class);
        $server = new JournalMcpServer($journal);
        $response = $server->handle(1, false, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        self::assertSame(['library_context'], array_column((array)($response['result']['tools'] ?? []), 'name'));
    }

    public function testReadOnlyConnectionRejectsWriteEvenIfNameIsKnown() : void
    {
        $journal = $this->createMock(JournalService::class);
        $journal->expects(self::never())->method('commit');
        $response = (new JournalMcpServer($journal))->handle(1, false, ['id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'commit_watched_movies', 'arguments' => ['id' => 'x', 'approval' => 'y']]]);

        self::assertSame(-32602, ($response['error']['code'] ?? null));
    }

    public function testWriteConnectionUsesTheSameJournalServiceAndExplicitOwner() : void
    {
        $journal = $this->createMock(JournalService::class);
        $journal->expects(self::once())->method('preview')->with(7, 'Heat (1995)', 'movie')->willReturn(['id' => 'draft']);
        $response = (new JournalMcpServer($journal))->handle(7, true, ['id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'preview_watched_movies', 'arguments' => ['text' => 'Heat (1995)']]]);

        self::assertFalse(($response['result']['isError'] ?? null));
        self::assertSame('{"id":"draft"}', ($response['result']['content'][0]['text'] ?? null));
    }

    public function testInternalExceptionsDoNotLeakCredentials() : void
    {
        $journal = $this->createStub(JournalService::class);
        $journal->method('context')->willThrowException(new \RuntimeException('secret-value'));
        $response = (new JournalMcpServer($journal))->handle(1, false, ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'movie_context']]);

        self::assertStringNotContainsString('secret-value', json_encode($response, JSON_THROW_ON_ERROR));
    }

    public function testNotificationsNeverProduceAResponse() : void
    {
        $server = new JournalMcpServer($this->createStub(JournalService::class));

        self::assertNull($server->handle(1, false, ['method' => 'notifications/initialized']));
        self::assertNull($server->handle(1, false, ['method' => 'ping']));
    }

    public function testMalformedMethodDoesNotTerminateTheServer() : void
    {
        $server = new JournalMcpServer($this->createStub(JournalService::class));
        $response = $server->handle(1, false, ['id' => 1, 'method' => []]);

        self::assertSame(-32600, ($response['error']['code'] ?? null));
    }
    public function testTvPreviewForwardsTheExplicitMediaType() : void
    {
        $journal = $this->createMock(JournalService::class);
        $journal->expects(self::once())->method('preview')->with(7, 'Heat (2024)', 'tv')->willReturn(['id' => 'draft']);
        $response = (new JournalMcpServer($journal))->handle(7, true, ['id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'preview_watched_titles', 'arguments' => ['text' => 'Heat (2024)', 'mediaType' => 'tv']]]);

        self::assertFalse(($response['result']['isError'] ?? null));
    }
}
