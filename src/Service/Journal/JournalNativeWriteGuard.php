<?php declare(strict_types=1);

namespace Movary\Service\Journal;

use Movary\Domain\User\Service\Authentication;
use Movary\HttpController\Web\Middleware\MiddlewareInterface;
use Movary\ValueObject\Http\Request;
use Movary\ValueObject\Http\Response;
use Movary\ValueObject\Http\StatusCode;

/** Friendly HTTP rejection; database guards also cover workers and integration writers. */
class JournalNativeWriteGuard implements MiddlewareInterface
{
    public function __construct(private readonly Authentication $authentication, private readonly LibraryRepository $repository)
    {
    }

    public function __invoke(Request $request) : ?Response
    {
        $owner = $this->authentication->authenticateWebSession() ?? $this->authentication->authenticateApiToken($request);
        if ($owner === null || $this->repository->ready($owner->getUserId()) === false) {
            return null;
        }

        return Response::createSensitiveJson('{"error":"This library uses reviewed personal records. Use Log a watch, Import history, or MCP preview_changes/commit_changes; native Movary personal writes are read-only."}', StatusCode::createConflict());
    }

    /** @param array<array-key,mixed> $handler */
    public static function protects(array $handler) : bool
    {
        [$class, $method] = $handler;
        if (in_array($class, [
            \Movary\HttpController\Api\HistoryController::class,
            \Movary\HttpController\Api\PlayedController::class,
            \Movary\HttpController\Api\WatchlistController::class,
            \Movary\HttpController\Web\HistoryController::class,
            \Movary\HttpController\Web\WatchlistController::class,
            \Movary\HttpController\Web\Movie\MovieWatchlistController::class,
            \Movary\HttpController\Web\Movie\MovieRatingController::class,
            \Movary\HttpController\Web\ImportController::class,
        ], true)) {
            return true;
        }

        return in_array($method, ['deleteHistory', 'deleteRatings', 'deleteAccount', 'importNetflixData'], true);
    }
}
