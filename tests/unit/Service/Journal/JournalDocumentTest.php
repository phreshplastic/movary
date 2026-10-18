<?php declare(strict_types=1);

namespace Tests\Unit\Movary\Service\Journal;

use InvalidArgumentException;
use Movary\Service\Journal\JournalDocument;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(JournalDocument::class)]
class JournalDocumentTest extends TestCase
{
    public function testRecallPreservesExactReactionAndUnknownDate() : void
    {
        $films = (new JournalDocument())->parse("Parasite (2019) | Enjoyed\nHeat (1995)");

        self::assertSame('Enjoyed', $films[0]['reaction']);
        self::assertSame('', $films[0]['date']);
        self::assertSame('Seen', $films[0]['status']);
    }

    public function testInvalidCalendarDateIsRejected() : void
    {
        $this->expectException(InvalidArgumentException::class);

        (new JournalDocument())->parse('Heat (1995) | Liked | 2026-02-30');
    }

    public function testDuplicateLinesAreRejected() : void
    {
        $this->expectException(InvalidArgumentException::class);

        (new JournalDocument())->parse("Heat (1995)\nHeat (1995)");
    }

    public function testMarkdownRoundTripKeepsLiteralDollarAndPipe() : void
    {
        $document = new JournalDocument();
        $films = $document->parse('A Movie (2000) | Loved | | Cost $1 and $2');
        $films[0]['reaction'] = 'Loved | great dialogue';
        $markdown = "# History\n\n| Film | Year | Status | Reaction | Watch date | Evidence / note |\n| --- | --- | --- | --- | --- | --- |\n\nFranchise uncertainty remains.\n";
        $rendered = $document->render($markdown, $films);

        self::assertSame($films, $document->read($rendered));
        self::assertStringEndsWith("Franchise uncertainty remains.\n", $rendered);
    }

    public function testUnrelatedSuggestionStaysUnconfirmed() : void
    {
        $document = new JournalDocument();
        $films = $document->parse('A Movie (2000)');
        $films[0]['status'] = 'Unconfirmed';
        $merged = $document->merge($films, $document->parse('Heat (1995) | Liked')[0]);

        self::assertSame('Unconfirmed', $merged['films'][0]['status']);
        self::assertSame('Seen', $merged['films'][1]['status']);
    }

    public function testExplicitRecallCanConfirmAnExistingSuggestion() : void
    {
        $document = new JournalDocument();
        $film = $document->parse('Heat (1995)')[0];
        $film['status'] = 'Unconfirmed';
        $merged = $document->merge([$film], $document->parse('Heat (1995) | Loved')[0]);

        self::assertCount(1, $merged['films']);
        self::assertNotNull($merged['before']);
        self::assertSame('Unconfirmed', $merged['before']['status']);
        self::assertSame('Seen', $merged['after']['status']);
    }

    public function testMissingDateNeverErasesKnownDate() : void
    {
        $document = new JournalDocument();
        $film = $document->parse('Heat (1995) | Liked | 2026-10-16')[0];
        $merged = $document->merge([$film], $document->parse('Heat (1995) | Loved')[0]);

        self::assertSame('2026-10-16', $merged['after']['date']);
        self::assertNotNull($merged['before']);
        self::assertSame('Liked', $merged['before']['reaction']);
        self::assertSame('Loved', $merged['after']['reaction']);
    }

    public function testConflictingDatesRequireSeparateReview() : void
    {
        $document = new JournalDocument();
        $this->expectException(InvalidArgumentException::class);

        $document->merge($document->parse('Heat (1995) | Liked | 2026-10-16'), $document->parse('Heat (1995) | Loved | 2026-10-17')[0]);
    }

    public function testNewNoteKeepsOriginalProvenanceAndDoesNotRepeatOnRetry() : void
    {
        $document = new JournalDocument();
        $old = $document->parse('Heat (1995) | Liked | | Survey confirmation.');
        $incoming = $document->parse('Heat (1995) | Loved | | Great dialogue.')[0];
        $merged = $document->merge($old, $incoming);
        $rerun = $document->merge($merged['films'], $incoming);

        self::assertSame('Survey confirmation.; Great dialogue.', $merged['after']['note']);
        self::assertSame($merged['after']['note'], $rerun['after']['note']);
    }
    public function testTvSectionRoundTripDoesNotChangeMovieRecords() : void
    {
        $document = new JournalDocument();
        $movies = $document->parse('Heat (1995) | Liked');
        $markdown = $document->render("| Film | Year | Status | Reaction | Watch date | Evidence / note |\n| --- | --- | --- | --- | --- | --- |\n\nFranchise uncertainty remains.\n", $movies);
        self::assertSame([], $document->readShows($markdown));
        $shows = $document->parse('Heat (2024) | Enjoyed | | Watched one season, not all episodes.');
        $shows[0]['note'] .= ' Cost $1 | exact.';
        $rendered = $document->renderShows($markdown, $shows);

        self::assertSame($movies, $document->read($rendered));
        self::assertSame($shows, $document->readShows($rendered));
        self::assertStringContainsString('Franchise uncertainty remains.', $rendered);
        $movies[0]['reaction'] = 'Loved';
        $updated = $document->render($rendered, $movies);
        self::assertSame($shows, $document->readShows($updated));
        self::assertSame($movies, $document->read($updated));
        self::assertSame($updated, $document->renderShows($updated, $shows));
    }

    public function testReviewDetailsPreserveFreeformReactionAndValidateDate() : void
    {
        $document = new JournalDocument();
        $film = $document->parse('Heat (1995)')[0];
        $updated = $document->withDetails($film, ['reaction' => 'Loved | great dialogue', 'note' => 'Only the first season', 'date' => '2020-02-29']);
        self::assertSame('Loved | great dialogue', $updated['reaction']);
        self::assertSame('2020-02-29', $updated['date']);
        $this->expectException(InvalidArgumentException::class);
        $document->withDetails($film, ['date' => '2021-02-29']);
    }

    public function testReviewDetailsCannotOverrideViewingEvidence() : void
    {
        $document = new JournalDocument();
        $this->expectException(InvalidArgumentException::class);
        $document->withDetails($document->parse('Heat (1995)')[0], ['status' => 'Not seen']);
    }
}
