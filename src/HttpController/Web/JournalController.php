<?php declare(strict_types=1);

namespace Movary\HttpController\Web;

use InvalidArgumentException;
use Movary\Domain\User\Service\CurrentWebUser;
use Movary\Service\Journal\JournalService;
use Movary\Service\Journal\PersonalLibrary;
use Movary\ValueObject\Http\Header;
use Movary\ValueObject\Http\Request;
use Movary\ValueObject\Http\Response;
use Movary\ValueObject\Http\StatusCode;
use Twig\Environment;

class JournalController
{
    public function __construct(
        private readonly CurrentWebUser $currentUser,
        private readonly JournalService $journal,
        private readonly Environment $twig,
        private readonly PersonalLibrary $library,
    ) {
    }

    public function home() : Response
    {
        return Response::createSensitiveSeeOther('/library');
    }

    public function library() : Response
    {
        return Response::createSensitive(StatusCode::createOk(), $this->twig->render('page/personal-library.html.twig', ['screen' => 'library', 'titleId' => '']));
    }

    public function page(Request $request) : Response
    {
        $parameters = $request->getRouteParameters();
        return Response::createSensitive(StatusCode::createOk(), $this->twig->render('page/personal-library.html.twig', [
            'screen' => $parameters['screen'] ?? (isset($parameters['id']) ? 'title' : 'log-watch'), 'titleId' => $parameters['id'] ?? '',
        ]));
    }

    public function libraryAction(Request $request) : Response
    {
        try {
            $input = json_decode($request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            if (is_array($input) === false) {
                throw new InvalidArgumentException('Expected a library request.');
            }
            $result = $this->executeLibrary($this->currentUser->requireUser()->getId(), $request->getRouteParameters()['action'], $input);
            return Response::createSensitiveJson(json_encode($result, JSON_THROW_ON_ERROR));
        } catch (InvalidArgumentException | \JsonException $e) {
            return Response::createSensitiveJson(json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR), StatusCode::createBadRequest());
        } catch (\Throwable) {
            return Response::createSensitiveJson('{"error":"The operation could not finish. Your existing library is available; retry the same Save to recover its receipt."}', StatusCode::createBadRequest());
        }
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function executeLibrary(int $userId, string $action, array $input) : array
    {
        $input += ['id' => '', 'creditsOffset' => 0, 'limit' => 25, 'offset' => 0, 'query' => '', 'mediaType' => 'all', 'page' => 1, 'changes' => [], 'requestId' => null, 'approval' => '', 'tasteOffset' => 0, 'fullStatements' => false, 'tmdbId' => 0];
        if (in_array($action, ['preview', 'commit', 'refresh'], true)) {
            return $this->mutateLibrary($userId, $action, $input);
        }
        return match ($action) {
            'context' => $this->library->context($userId, (int)$input['tasteOffset'], (bool)$input['fullStatements']),
            'search' => $this->library->search($userId, $input),
            'facets' => ['items' => $this->library->facets($userId)],
            'title' => $this->library->title($userId, (string)$input['id'], (int)$input['creditsOffset']),
            'history' => $this->library->history($userId, (string)$input['id'], (int)$input['limit'], (int)$input['offset']),
            'catalog' => $this->library->catalog($userId, (string)$input['query'], (string)$input['mediaType'], (int)$input['page']),
            'catalog-title' => ['metadata' => $this->library->catalogTitle((string)$input['mediaType'], (int)$input['tmdbId'])],
            default => throw new InvalidArgumentException('Unknown library operation.'),
        };
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function mutateLibrary(int $userId, string $action, array $input) : array
    {
        return match ($action) {
            'preview' => $this->library->preview($userId, array_values((array)$input['changes']), $input['requestId']),
            'commit' => $this->library->commit($userId, (string)$input['id'], (string)$input['approval']),
            'refresh' => $this->library->refresh($userId, (string)$input['id']),
            default => throw new InvalidArgumentException('Unknown personal mutation.'),
        };
    }

    public function render() : Response
    {
        return Response::createSensitive(StatusCode::createOk(), $this->twig->render('page/journal.html.twig', [
            'journal' => $this->journal->context($this->currentUser->requireUser()->getId()),
        ]));
    }

    public function action(Request $request) : Response
    {
        try {
            $input = json_decode($request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            if (is_array($input) === false) {
                throw new InvalidArgumentException('Expected a journal request.');
            }
            $userId = $this->currentUser->requireUser()->getId();
            $action = $request->getRouteParameters()['action'];
            $result = $this->execute($userId, $action, $input);

            return Response::createSensitiveJson(json_encode($result, JSON_THROW_ON_ERROR));
        } catch (InvalidArgumentException | \JsonException $e) {
            return Response::createSensitiveJson(json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR), StatusCode::createBadRequest());
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function execute(int $userId, string $action, array $input) : array
    {
        $input += ['text' => '', 'mediaType' => 'movie', 'id' => '', 'choices' => [], 'details' => [], 'approval' => ''];
        return match ($action) {
            'preview' => $this->journal->preview($userId, (string)$input['text'], (string)$input['mediaType']),
            'review' => $this->journal->review($userId, (string)$input['id'], (array)$input['choices'], (array)$input['details']),
            'commit' => $this->journal->commit($userId, (string)$input['id'], (string)$input['approval']),
            default => throw new InvalidArgumentException('Unknown journal operation.'),
        };
    }

    public function export(Request $request) : Response
    {
        $format = $request->getRouteParameters()['format'];

        return Response::createSensitive(StatusCode::createOk(),
            $this->journal->export($this->currentUser->requireUser()->getId(), $format),
            [Header::createAttachment($format === 'csv' ? 'watch-history.csv' : ($format === 'json' ? 'library-v1.json' : 'movie-context.md'))]);
    }
}
