<?php

declare(strict_types=1);

namespace Relay\Support;

use PDO;
use PDOException;
use Throwable;

final class Database
{
    private ?PDO $pdo = null;

    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        if (!extension_loaded('pdo_pgsql')) {
            throw new \RuntimeException('PHP extension pdo_pgsql is required.');
        }

        $dsn = $this->config['url'] ?? null;
        if (is_string($dsn) && $dsn !== '') {
            if (str_starts_with($dsn, 'postgres://') || str_starts_with($dsn, 'postgresql://')) {
                $parts = parse_url($dsn);
                if ($parts === false || empty($parts['host'])) {
                    throw new \RuntimeException('Invalid DATABASE_URL.');
                }
                $dsn = sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    $parts['host'],
                    $parts['port'] ?? 5432,
                    ltrim($parts['path'] ?? '/opencode_relay', '/')
                );
                $user = urldecode($parts['user'] ?? '');
                $pass = urldecode($parts['pass'] ?? '');
            } elseif (str_starts_with($dsn, 'pgsql:')) {
                $user = $this->config['user'] ?? '';
                $pass = $this->config['password'] ?? '';
            } else {
                throw new \RuntimeException('DATABASE_URL must be a pgsql DSN or postgres:// URL.');
            }
        } else {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                $this->config['host'] ?? '127.0.0.1',
                (int) ($this->config['port'] ?? 5432),
                $this->config['name'] ?? 'opencode_relay',
                $this->config['sslmode'] ?? 'prefer'
            );
            $user = $this->config['user'] ?? '';
            $pass = $this->config['password'] ?? '';
        }

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->pdo->exec("SET TIME ZONE 'UTC'");
        return $this->pdo;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * @template T
     * @param callable(PDO):T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function advisoryLock(string $name, bool $wait = false): bool
    {
        $key = $this->advisoryKey($name);
        if ($wait) {
            $stmt = $this->pdo()->prepare('SELECT pg_advisory_lock(?)');
            $stmt->execute([$key]);
            return true;
        }
        $stmt = $this->pdo()->prepare('SELECT pg_try_advisory_lock(?)');
        $stmt->execute([$key]);
        return (bool) $stmt->fetchColumn();
    }

    public function advisoryUnlock(string $name): void
    {
        $stmt = $this->pdo()->prepare('SELECT pg_advisory_unlock(?)');
        $stmt->execute([$this->advisoryKey($name)]);
    }

    private function advisoryKey(string $name): int
    {
        $parts = unpack('N2', hash('sha256', $name, true));
        // Keep it in signed 64-bit range.
        return (($parts[1] & 0x7fffffff) << 32) | $parts[2];
    }

    public function healthy(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }
}
