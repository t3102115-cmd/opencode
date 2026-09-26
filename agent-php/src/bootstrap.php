<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    $path = __DIR__ . '/' . $class . '.php';
    if (is_file($path)) {
        require $path;
    }
});

foreach (['workspaces', 'logs', 'pids'] as $sub) {
    $dir = Config::dataDir() . '/' . $sub;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
