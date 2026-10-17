#!/usr/bin/env php
<?php declare(strict_types=1);

use Movary\Domain\User\UserApi;
use Movary\Service\Journal\JournalMcpServer;

// No network listener or bearer-token fallback: the parent process owns access.
$options = getopt('', ['user-id:', 'write']);
$userId = filter_var($options['user-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId === false) {
    fwrite(STDERR, "An explicit --user-id is required. Access is read-only unless --write is supplied.\n");
    exit(1);
}
$container = require dirname(__DIR__) . '/bootstrap.php';
$container->get(UserApi::class)->fetchUser($userId);
$server = $container->get(JournalMcpServer::class);
while (($line = fgets(STDIN, 1000001)) !== false) {
    if (strlen($line) > 1000000 || str_ends_with($line, "\n") === false) {
        fwrite(STDERR, "MCP request too large or missing newline.\n");
        exit(1);
    }
    try {
        $request = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (is_array($request) === false || ($request['jsonrpc'] ?? '') !== '2.0') {
            throw new JsonException('Expected a JSON-RPC object.');
        }
        $response = $server->handle($userId, array_key_exists('write', $options), $request);
    } catch (JsonException) {
        $response = ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Invalid JSON-RPC request.']];
    }
    if ($response !== null) {
        fwrite(STDOUT, json_encode($response, JSON_THROW_ON_ERROR) . "\n");
        fflush(STDOUT);
    }
}
