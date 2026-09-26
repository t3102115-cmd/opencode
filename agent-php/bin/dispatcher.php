#!/usr/bin/env php
<?php

declare(strict_types=1);

// Run this from cron every minute, e.g.:
//   * * * * * php /path/to/agent-php/bin/dispatcher.php >> /path/to/agent-php/data/logs/dispatcher.log 2>&1
//
// It looks for sessions that still need work and are not already being
// worked on (checked via the same flock the worker itself takes), and
// spawns a detached "php worker.php <id>" for each. This is what makes a
// session keep making progress even after you close the browser tab: the
// browser only ever reads state, it never has to stay connected.

require __DIR__ . '/../src/bootstrap.php';

$phpBinary = PHP_BINARY;
$workerScript = __DIR__ . '/worker.php';

$active = array_filter(SessionRepo::all(), static fn (array $s) => in_array($s['state'], ['pending', 'running', 'finishing'], true));

foreach ($active as $session) {
    $lockPath = Config::dataDir() . '/pids/' . $session['id'] . '.lock';
    $handle = fopen($lockPath, 'c');
    if ($handle === false) {
        continue;
    }

    $locked = flock($handle, LOCK_EX | LOCK_NB);
    if (!$locked) {
        // Already running.
        fclose($handle);
        continue;
    }
    flock($handle, LOCK_UN);
    fclose($handle);

    $logFile = Config::dataDir() . '/logs/' . $session['id'] . '.log';
    $cmd = sprintf(
        'nohup %s %s %s >> %s 2>&1 & echo $!',
        escapeshellarg($phpBinary),
        escapeshellarg($workerScript),
        escapeshellarg($session['id']),
        escapeshellarg($logFile)
    );
    exec($cmd);
    echo "[dispatcher] launched worker for session {$session['id']}\n";
}
