<?php declare(strict_types=1);

namespace Movary\HttpController\Web;

use InvalidArgumentException;
use Movary\Domain\User\Service\CurrentWebUser;
use Movary\Service\Journal\JournalService;
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
    ) {
    }

    public function home() : Response
    {
        return Response::createSensitiveSeeOther('/library');
    }

    public function library() : Response
    {
        return Response::createSensitive(StatusCode::createOk(), $this->twig->render('page/library.html.twig', [
            'journal' => $this->journal->context($this->currentUser->requireUser()->getId()),
        ]));
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
            [Header::createAttachment($format === 'csv' ? 'watch-history.csv' : 'movie-context.md')]);
    }
}
