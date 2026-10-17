<?php declare(strict_types=1);

namespace Movary\Service\Journal;

use InvalidArgumentException;

/**
 * @phpstan-type Film array{title:string,year:string,status:string,reaction:string,date:string,note:string}
 * @psalm-type Film = array{title:string,year:string,status:string,reaction:string,date:string,note:string}
 */
class JournalDocument
{
    public const array COLUMNS = ['title', 'year', 'status', 'reaction', 'date', 'note'];

    public function normalize(string $title) : string
    {
        return (string)preg_replace('/[^\pL\pN]/u', '', mb_strtolower($title));
    }

    /** @return list<Film> */
    public function read(string $markdown) : array
    {
        $films = [];
        foreach (explode("\n", $markdown) as $line) {
            if (str_starts_with($line, '|') === false) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, "|\r\n")));
            if (($cells[0]) === 'Film' || preg_match('/^[-: ]+$/', $cells[0]) === 1) {
                continue;
            }
            if (count($cells) !== 6 || in_array($cells[2], ['Seen', 'Not seen', 'Unconfirmed'], true) === false) {
                throw new InvalidArgumentException('The journal table must have six columns and a valid viewing status.');
            }
            $cells = array_map(static fn(string $v) : string => str_replace('&#124;', '|', $v), $cells);
            $films[] = ['title' => $cells[0], 'year' => $cells[1], 'status' => $cells[2],
                'reaction' => $cells[3], 'date' => $cells[4], 'note' => $cells[5]];
        }
        if ($films === []) {
            throw new InvalidArgumentException('The journal has not been initialized.');
        }

        return $films;
    }

    /** @return list<Film> */
    public function parse(string $text) : array
    {
        if (strlen($text) > 100000) {
            throw new InvalidArgumentException('Please split this into smaller batches.');
        }
        $films = [];
        foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $film = $this->parseLine($line);
            $key = $this->normalize($film['title']) . ':' . $film['year'];
            if (isset($films[$key])) {
                throw new InvalidArgumentException('A title appears more than once in this batch. Combine it into one row.');
            }
            $films[$key] = $film;
        }
        if ($films === [] || count($films) > 100) {
            throw new InvalidArgumentException('Paste between 1 and 100 watched movies.');
        }

        return array_values($films);
    }

    /** @return Film */
    private function parseLine(string $line) : array
    {
        $cells = array_map('trim', preg_split('/[|\t]/', $line) ?: []);
        if (count($cells) > 4) {
            throw new InvalidArgumentException('Use one title per line, optionally followed by reaction, date, and note.');
        }
        $cells = array_pad($cells, 4, '');
        $year = '';
        $title = $cells[0];
        if (preg_match('/^(.+?)\s+\((\d{4})\)$/u', $title, $m) === 1) {
            $title = $m[1];
            $year = $m[2];
        }
        if ($title === '') {
            throw new InvalidArgumentException('Each line needs a movie title.');
        }
        if ($cells[2] !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $cells[2]);
            if ($date === false || $date->format('Y-m-d') !== $cells[2]) {
                throw new InvalidArgumentException('Use YYYY-MM-DD for known dates, or leave the date blank.');
            }
        }

        return ['title' => $title, 'year' => $year, 'status' => 'Seen', 'reaction' => $cells[1], 'date' => $cells[2], 'note' => $cells[3]];
    }

    /**
     * @param list<Film> $films
     * @param Film $incoming
     * @return array{films:list<Film>,before:Film|null,after:Film}
     */
    public function merge(array $films, array $incoming) : array
    {
        foreach ($films as $i => $old) {
            if ($this->normalize($old['title']) !== $this->normalize($incoming['title'])) {
                continue;
            }
            if ($old['year'] !== $incoming['year']) {
                if ($old['year'] === '' || $incoming['year'] === '') {
                    throw new InvalidArgumentException('Please supply the year for ' . $incoming['title'] . ' to avoid a duplicate version.');
                }
                continue;
            }
            if ($old['date'] !== '' && $incoming['date'] !== '' && $old['date'] !== $incoming['date']) {
                throw new InvalidArgumentException('A different watch date already exists for ' . $incoming['title'] . '. Rewatches need separate review.');
            }
            $new = $old;
            $new['status'] = 'Seen';
            $new['reaction'] = $this->providedOrExisting($incoming['reaction'], $old['reaction']);
            $new['date'] = $this->providedOrExisting($incoming['date'], $old['date']);
            $new['note'] = $this->appendNote($old['note'], $incoming['note']);
            $films[$i] = $new;

            return ['films' => $films, 'before' => $old, 'after' => $new];
        }
        $incoming['note'] = $incoming['note'] ?: 'User confirmed watching; journal intake.';
        $films[] = $incoming;

        return ['films' => $films, 'before' => null, 'after' => $incoming];
    }

    private function providedOrExisting(string $provided, string $existing) : string
    {
        return $provided === '' ? $existing : $provided;
    }

    private function appendNote(string $existing, string $incoming) : string
    {
        if ($incoming === '' || str_contains($existing, $incoming)) {
            return $existing;
        }

        return $existing . ($existing === '' ? '' : '; ') . $incoming;
    }

    /** @param list<Film> $films */
    public function render(string $markdown, array $films) : string
    {
        $table = "| Film | Year | Status | Reaction | Watch date | Evidence / note |\n| --- | ---: | --- | --- | --- | --- |\n";
        foreach ($films as $film) {
            $table .= '| ' . implode(' | ', array_map(static fn(string $v) : string => str_replace(["\n", '|'], [' ', '&#124;'], $v), array_values($film))) . " |\n";
        }
        $result = preg_replace_callback('/^\|[^\n]*\n(?:\|[^\n]*(?:\n|$))*/m', static fn() : string => $table, $markdown, 1);
        if ($result === null) {
            throw new InvalidArgumentException('Unable to render the journal.');
        }

        return $result;
    }
}
