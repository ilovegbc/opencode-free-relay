<?php

declare(strict_types=1);

namespace Relay\Support;

/**
 * Tiny, dependency-free extension point. Plugins (or local `app/plugins`
 * files) can register callbacks without patching the core:
 *
 *   Hook::on('relay.before_upstream', function (array $ctx): array { ... });
 *
 * Keep handlers fast; they run inside the request path.
 */
final class Hook
{
    /** @var array<string,list<callable>> */
    private static array $handlers = [];

    public static function on(string $event, callable $handler): void
    {
        self::$handlers[$event][] = $handler;
    }

    /**
     * Run handlers in order. If a handler returns an array it replaces the
     * current payload (useful for request/header transforms). Returning null
     * leaves the payload unchanged.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function filter(string $event, array $payload, mixed $context = null): array
    {
        foreach (self::$handlers[$event] ?? [] as $handler) {
            $result = $handler($payload, $context);
            if (is_array($result)) {
                $payload = $result;
            }
        }
        return $payload;
    }

    public static function fire(string $event, mixed $payload = null): void
    {
        foreach (self::$handlers[$event] ?? [] as $handler) {
            $handler($payload);
        }
    }
}
