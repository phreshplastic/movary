<?php declare(strict_types=1);

namespace Tests\Unit\Movary\Service\Journal;

use Movary\Service\Journal\JournalStore;
use RuntimeException;

class InterruptibleJournalStore extends JournalStore
{
    public bool $failCanonical = false;

    public function __construct(private readonly string $root)
    {
    }

    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
    public function directory(int $userId) : string
    {
        return $this->root;
    }

    public function write(int $userId, string $name, string $value) : void
    {
        if ($this->failCanonical && $name === 'WATCH_HISTORY.md') {
            $this->failCanonical = false;
            throw new RuntimeException('Simulated interrupted canonical write');
        }
        parent::write($userId, $name, $value);
    }
}
