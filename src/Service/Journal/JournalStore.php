<?php declare(strict_types=1);

namespace Movary\Service\Journal;

use InvalidArgumentException;
use RuntimeException;

class JournalStore
{
    public function directory(int $userId) : string
    {
        return dirname(__DIR__, 3) . '/storage/.journal/' . $userId;
    }

    public function read(int $userId, string $name) : string
    {
        $path = $this->directory($userId) . '/' . $name;
        $value = is_file($path) ? file_get_contents($path) : false;
        if ($value === false) {
            throw new RuntimeException('Your private journal needs to be initialized by the administrator.');
        }

        return $value;
    }

    public function write(int $userId, string $name, string $value) : void
    {
        $directory = $this->directory($userId);
        if (is_dir($directory) === false && mkdir($directory, 0700, true) === false) {
            throw new RuntimeException('Unable to create private journal storage.');
        }
        $temp = tempnam($directory, '.write-');
        if ($temp === false || file_put_contents($temp, $value) === false) {
            throw new RuntimeException('Unable to save journal data.');
        }
        chmod($temp, 0600);
        if (rename($temp, $directory . '/' . $name) === false) {
            throw new RuntimeException('Unable to replace journal data.');
        }
    }

    /** @return array<string,mixed> */
    public function draft(int $userId, string $id) : array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid review identifier.');
        }
        $draft = json_decode($this->read($userId, 'draft-' . $id . '.json'), true, flags: JSON_THROW_ON_ERROR);
        if (is_array($draft) === false || (isset($draft['receipt']) === false && ($draft['expires'] ?? 0) < time())) {
            throw new InvalidArgumentException('This review expired. Paste the batch again.');
        }

        return $draft;
    }

    /** @param array<string,mixed> $draft */
    public function saveDraft(int $userId, string $id, array $draft) : void
    {
        $this->write($userId, 'draft-' . $id . '.json', json_encode($draft, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * @template T
     * @param callable():T $action
     * @return T
     */
    public function locked(int $userId, callable $action) : mixed
    {
        $handle = fopen($this->directory($userId) . '/journal.lock', 'c');
        if ($handle === false || flock($handle, LOCK_EX) === false) {
            throw new RuntimeException('The journal is busy. Please try again.');
        }
        try {
            return $action();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
