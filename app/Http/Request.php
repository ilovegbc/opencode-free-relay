<?php

declare(strict_types=1);

namespace Relay\Http;

use Relay\App;
use Relay\Support\Session;

final class Request
{
    private ?array $json = null;
    private bool $jsonParsed = false;
    private ?string $rawBody = null;

    public function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $base = trim((string) App::config('app.base_path', ''), '/');
        if ($base !== '') {
            $prefix = '/' . $base;
            if ($path === $prefix) {
                $path = '/';
            } elseif (str_starts_with($path, $prefix . '/')) {
                $path = substr($path, strlen($prefix));
            }
        }
        // Built-in server with /index.php/router style URLs.
        if ($path === '/index.php') {
            $path = '/';
        }
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path(), '/v1/')
            || $this->path() === '/v1'
            || str_starts_with($this->path(), '/api/')
            || $this->path() === '/health';
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (array_key_exists($key, $_SERVER)) {
            return (string) $_SERVER[$key];
        }
        if (strcasecmp($name, 'Content-Type') === 0 && isset($_SERVER['CONTENT_TYPE'])) {
            return (string) $_SERVER['CONTENT_TYPE'];
        }
        if (strcasecmp($name, 'Content-Length') === 0 && isset($_SERVER['CONTENT_LENGTH'])) {
            return (string) $_SERVER['CONTENT_LENGTH'];
        }
        return $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
            return trim($m[1]);
        }
        $xApiKey = $this->header('x-api-key');
        return $xApiKey !== null && trim($xApiKey) !== '' ? trim($xApiKey) : null;
    }

    public function rawBody(): string
    {
        if ($this->rawBody === null) {
            $body = file_get_contents('php://input');
            $this->rawBody = $body === false ? '' : $body;
        }
        return $this->rawBody;
    }

    public function json(): array
    {
        if ($this->jsonParsed) {
            return $this->json ?? [];
        }
        $this->jsonParsed = true;
        $raw = trim($this->rawBody());
        if ($raw === '') {
            $this->json = [];
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(400, 'Request body must be a valid JSON object.', 'invalid_json');
        }
        $this->json = $decoded;
        return $decoded;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();
        return $json[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_GET)) {
            return $_GET[$key];
        }
        $normalized = str_replace(['.', ' '], '_', $key);
        return $_GET[$normalized] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }
        // PHP normalises dots and spaces in form field names to underscores.
        $normalized = str_replace(['.', ' '], '_', $key);
        return $_POST[$normalized] ?? $default;
    }

    public function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (App::config('session.trust_proxy', false)) {
            $forwarded = $this->header('x-forwarded-for');
            if ($forwarded) {
                $candidate = trim(explode(',', $forwarded)[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
            $real = $this->header('x-real-ip');
            if ($real && filter_var(trim($real), FILTER_VALIDATE_IP)) {
                return trim($real);
            }
        }
        return $remote;
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->header('User-Agent') ?? ''), 0, 512);
    }

    public function isSecure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (App::config('session.trust_proxy', false)) {
            return strtolower((string) $this->header('x-forwarded-proto')) === 'https';
        }
        return false;
    }

    public function csrfToken(): ?string
    {
        $json = $this->jsonParsed ? ($this->json ?? []) : null;
        if (is_array($json) && isset($json['_token'])) {
            return (string) $json['_token'];
        }
        return isset($_POST['_token']) ? (string) $_POST['_token'] : null;
    }

    public function validateCsrf(): void
    {
        if (!Session::verifyCsrf($this->csrfToken())) {
            throw new HttpException(419, 'Your session expired. Please refresh and try again.', 'csrf_mismatch');
        }
    }
}
