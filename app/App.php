<?php

declare(strict_types=1);

namespace Relay;

use Relay\Support\Config;
use Relay\Support\Database;
use Relay\Support\Logger;
use Relay\Support\Settings;
use Relay\Support\View;

final class App
{
    private static ?Config $config = null;
    private static ?Database $database = null;
    private static ?Settings $settings = null;
    private static string $basePath = '';

    public static function boot(Config $config, string $basePath): void
    {
        self::$config = $config;
        self::$basePath = rtrim($basePath, '/\\');
    }

    public static function config(?string $key = null, mixed $default = null): mixed
    {
        if (self::$config === null) {
            throw new \RuntimeException('Application has not been booted.');
        }
        return $key === null ? self::$config : self::$config->get($key, $default);
    }

    public static function db(): Database
    {
        if (self::$database === null) {
            self::$database = new Database(self::config('db'));
        }
        return self::$database;
    }

    public static function settings(): Settings
    {
        if (self::$settings === null) {
            self::$settings = new Settings(self::db(), self::config()->all());
        }
        return self::$settings;
    }

    public static function resetSettings(): void
    {
        self::$settings = null;
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    public static function url(string $path = ''): string
    {
        $base = rtrim((string) self::config('app.url', ''), '/');
        $basePath = trim((string) self::config('app.base_path', ''), '/');
        $path = '/' . ltrim($path, '/');
        if ($basePath !== '') {
            $path = '/' . $basePath . $path;
        }
        return $base . $path;
    }

    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = self::$basePath . '/public/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '1';
        return self::url('/assets/' . $path) . '?v=' . rawurlencode($version);
    }

    public static function redirect(string $path, int $status = 302): never
    {
        $target = preg_match('#^https?://#i', $path) ? $path : self::url($path);
        header('Location: ' . $target, true, $status);
        exit;
    }

    public static function log(): Logger
    {
        return new Logger();
    }
}
