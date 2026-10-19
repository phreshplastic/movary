#!/usr/bin/env php
<?php declare(strict_types=1);

use Movary\Service\Journal\LibraryMigration;
use Movary\Service\Journal\LibraryRepository;
use Movary\Service\Journal\PersonalLibrary;

$options = getopt('', ['user-id:', 'apply:', 'enrich', 'export:', 'match-exact', 'commit-id:', 'approval:']);
$userId = filter_var($options['user-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId === false) {
    fwrite(STDERR, "An explicit --user-id is required.\n");
    exit(1);
}
$c = require dirname(__DIR__) . '/bootstrap.php';
$c->get(\Movary\Domain\User\UserApi::class)->fetchUser($userId);
try {
    $migration = $c->get(LibraryMigration::class);
    $library = $c->get(PersonalLibrary::class);
    if (isset($options['commit-id'], $options['approval'])) {
        echo PersonalLibrary::json($library->commit($userId, (string)$options['commit-id'], (string)$options['approval'])) . "\n";
    } elseif (isset($options['match-exact'])) {
        $repo = $c->get(LibraryRepository::class);
        $document = $c->get(\Movary\Service\Journal\JournalDocument::class);
        $changes = [];
        $unresolved = [];
        foreach ($repo->db->fetchAllAssociative('SELECT id, media_type, title, release_year FROM journal_title WHERE user_id = ? AND tmdb_id IS NULL ORDER BY title', [$userId]) as $row) {
            try {
                $found = $library->catalog($userId, $row['title'], $row['media_type']);
                $exact = array_values(array_filter($found['items'], static fn(array $item) : bool => $item['year'] === $row['release_year'] && $document->normalize($item['title']) === $document->normalize($row['title'])));
                if (count($exact) === 1) {
                    $changes[] = ['action' => 'resolve_identity', 'titleId' => $row['id'], 'tmdbId' => $exact[0]['tmdbId']];
                } else {
                    $unresolved[] = ['id' => $row['id'], 'title' => $row['title'], 'reason' => 'No unique exact title/year match; human review required.'];
                }
            } catch (\InvalidArgumentException) {
                $unresolved[] = ['id' => $row['id'], 'title' => $row['title'], 'reason' => 'Provider unavailable; personal record preserved.'];
            }
        }
        echo PersonalLibrary::json(['unresolved' => $unresolved, 'preview' => $changes === [] ? null : $library->preview($userId, $changes)]) . "\n";
    } elseif (isset($options['export'])) {
        echo $library->export($userId, (string)$options['export']);
    } elseif (isset($options['enrich'])) {
        $repo = $c->get(LibraryRepository::class);
        foreach ($repo->db->fetchAllAssociative('SELECT id, title FROM journal_title WHERE user_id = ? AND tmdb_id IS NOT NULL', [$userId]) as $row) {
            try {
                $library->refresh($userId, $row['id']);
                echo PersonalLibrary::json(['id' => $row['id'], 'metadata' => 'refreshed']) . "\n";
            } catch (\InvalidArgumentException) {
                echo PersonalLibrary::json(['id' => $row['id'], 'metadata' => 'unavailable']) . "\n";
            }
        }
    } else {
        echo PersonalLibrary::json(isset($options['apply']) ? $migration->migrate($userId, (string)$options['apply']) : $migration->preview($userId)) . "\n";
    }
} catch (\Throwable) {
    fwrite(STDERR, "Library maintenance could not finish. Personal data and credentials are not printed in errors.\n");
    exit(1);
}
