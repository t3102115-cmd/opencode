#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$sessionId = $argv[1] ?? '';
if ($sessionId === '') {
    fwrite(STDERR, "Usage: php worker.php <session_id>\n");
    exit(1);
}

$lockPath = Config::dataDir() . '/pids/' . $sessionId . '.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Session $sessionId is already being worked on.\n");
    exit(0);
}

ftruncate($lockHandle, 0);
fwrite($lockHandle, (string) getmypid());

try {
    Worker::runOnce($sessionId);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
