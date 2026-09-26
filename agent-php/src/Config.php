<?php

declare(strict_types=1);

final class Config
{
    private static ?array $data = null;

    public static function root(): string
    {
        return dirname(__DIR__);
    }

    public static function dataDir(): string
    {
        return self::root() . '/data';
    }

    public static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $path = self::dataDir() . '/settings.json';
        $defaults = [
            'admin_password_hash' => '',
            'zen_api_key' => '',
            'github_token' => '',
            'default_model' => 'big-pickle',
            'zen_base_url' => 'https://opencode.ai/zen/v1',
            'max_steps_per_tick' => 8,
            'keep_workspace_after_done' => false,
        ];

        if (is_file($path)) {
            $stored = json_decode((string) file_get_contents($path), true);
            if (is_array($stored)) {
                $defaults = array_merge($defaults, $stored);
            }
        }

        self::$data = $defaults;

        return self::$data;
    }

    public static function get(string $key)
    {
        $data = self::load();

        return $data[$key] ?? null;
    }

    public static function save(array $partial): void
    {
        $current = self::load();
        $merged = array_merge($current, $partial);
        $path = self::dataDir() . '/settings.json';
        file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT));
        chmod($path, 0600);
        self::$data = $merged;
    }
}
