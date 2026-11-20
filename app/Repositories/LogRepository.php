<?php

declare(strict_types=1);

namespace Relay\Repositories;

use Relay\Support\Database;

final class LogRepository
{
    public function __construct(private Database $db)
    {
    }

    public function insertRequest(array $log): void
    {
        $this->db->execute(
            'INSERT INTO request_logs
                (request_id, user_id, api_key_id, model_id, endpoint, is_stream, status_code, upstream_status,
                 error_code, error_message, ip_pool_id, ip_address, attempt_count, input_tokens, output_tokens,
                 total_tokens, cached_tokens, latency_ms, first_byte_ms, client_ip, user_agent,
                 prompt_cache_key, request_bytes, response_bytes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::inet, ?, ?, ?, ?, ?, ?, ?, ?::inet, ?, ?, ?, ?)',
            [
                $log['request_id'],
                $log['user_id'] ?? null,
                $log['api_key_id'] ?? null,
                $log['model_id'] ?? null,
                $log['endpoint'] ?? '',
                !empty($log['is_stream']) ? 'true' : 'false',
                $log['status_code'] ?? 0,
                $log['upstream_status'] ?? 0,
                $log['error_code'] ?? null,
                $log['error_message'] ?? null,
                $log['ip_pool_id'] ?? null,
                $log['ip_address'] ?? null,
                $log['attempt_count'] ?? 1,
                $log['input_tokens'] ?? 0,
                $log['output_tokens'] ?? 0,
                $log['total_tokens'] ?? 0,
                $log['cached_tokens'] ?? 0,
                $log['latency_ms'] ?? 0,
                $log['first_byte_ms'] ?? 0,
                $log['client_ip'] ?? null,
                isset($log['user_agent']) ? mb_substr((string) $log['user_agent'], 0, 512) : null,
                isset($log['prompt_cache_key']) ? mb_substr((string) $log['prompt_cache_key'], 0, 200) : null,
                $log['request_bytes'] ?? 0,
                $log['response_bytes'] ?? 0,
            ]
        );
    }

    public function insertAttempt(array $attempt): void
    {
        $this->db->execute(
            'INSERT INTO upstream_attempts
                (request_id, user_id, attempt, pool_id, ip_address, status_code, error_code, error_message, latency_ms)
             VALUES (?, ?, ?, ?, ?::inet, ?, ?, ?, ?)',
            [
                $attempt['request_id'],
                $attempt['user_id'] ?? null,
                $attempt['attempt'] ?? 1,
                $attempt['pool_id'] ?? null,
                $attempt['ip_address'] ?? null,
                $attempt['status_code'] ?? 0,
                $attempt['error_code'] ?? null,
                isset($attempt['error_message']) ? mb_substr((string) $attempt['error_message'], 0, 1000) : null,
                $attempt['latency_ms'] ?? 0,
            ]
        );
    }

    /** @return list<array<string,mixed>> */
    public function paginate(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->where($filters);
        $params[] = $limit;
        $params[] = $offset;
        return $this->db->fetchAll(
            'SELECT l.*, u.username, m.name AS model_name
             FROM request_logs l
             LEFT JOIN users u ON u.id = l.user_id
             LEFT JOIN model_catalog m ON m.id = l.model_id
             ' . $where . ' ORDER BY l.id DESC LIMIT ? OFFSET ?',
            $params
        );
    }

    public function count(array $filters = []): int
    {
        [$where, $params] = $this->where($filters);
        return (int) $this->db->scalar('SELECT count(*) FROM request_logs l ' . $where, $params);
    }

    /** @return array{0:string,1:list<mixed>} */
    private function where(array $filters): array
    {
        $parts = [];
        $params = [];
        if (!empty($filters['user_id'])) {
            $parts[] = 'l.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['model'])) {
            $parts[] = 'l.model_id = ?';
            $params[] = (string) $filters['model'];
        }
        if (!empty($filters['status'])) {
            $parts[] = 'l.status_code = ?';
            $params[] = (int) $filters['status'];
        }
        if (!empty($filters['ip'])) {
            $parts[] = 'l.ip_address = ?::inet';
            $params[] = (string) $filters['ip'];
        }
        if (!empty($filters['since'])) {
            $parts[] = 'l.created_at >= ?::timestamptz';
            $params[] = (string) $filters['since'];
        }
        return [$parts === [] ? '' : 'WHERE ' . implode(' AND ', $parts), $params];
    }

    /** @return list<array<string,mixed>> */
    public function attempts(string $requestId): array
    {
        return $this->db->fetchAll('SELECT * FROM upstream_attempts WHERE request_id = ?::uuid ORDER BY attempt', [$requestId]);
    }

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        return $this->db->fetch(
            'SELECT
                count(*) FILTER (WHERE created_at >= date_trunc(\'day\', now())) AS today_requests,
                coalesce(sum(total_tokens) FILTER (WHERE created_at >= date_trunc(\'day\', now())), 0) AS today_tokens,
                count(*) FILTER (WHERE status_code >= 200 AND status_code < 300) AS ok_requests,
                count(*) FILTER (WHERE status_code >= 400) AS failed_requests,
                coalesce(round(avg(latency_ms)), 0) AS avg_latency,
                count(*) AS total_requests
             FROM request_logs'
        ) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function dailySeries(int $days = 14, ?int $userId = null): array
    {
        $params = [];
        $userWhere = '';
        if ($userId !== null) {
            $userWhere = 'AND user_id = ?';
            $params[] = $userId;
        }
        $params[] = max(1, $days);
        return $this->db->fetchAll(
            'SELECT day, sum(requests) AS requests, sum(input_tokens) AS input_tokens,
                    sum(output_tokens) AS output_tokens
             FROM usage_daily
             WHERE day >= current_date - (?::int - 1) ' . $userWhere . '
             GROUP BY day ORDER BY day',
            $params
        );
    }

    /** @return list<array<string,mixed>> */
    public function usageForUser(int $userId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM request_logs WHERE user_id = ? ORDER BY id DESC LIMIT ?',
            [$userId, $limit]
        );
    }

    public function audit(?int $actorId, string $action, string $targetType = '', string $targetId = '', array $details = [], ?string $ip = null): void
    {
        $this->db->execute(
            'INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, details, ip)
             VALUES (?, ?, ?, ?, ?::jsonb, ?::inet)',
            [
                $actorId,
                $action,
                $targetType,
                $targetId,
                json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $ip,
            ]
        );
    }

    /** @return list<array<string,mixed>> */
    public function auditList(int $limit = 100, int $offset = 0): array
    {
        return $this->db->fetchAll(
            'SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
             ORDER BY a.id DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
    }

    public function recordLoginAttempt(string $identifier, ?string $ip, bool $success): void
    {
        $this->db->execute(
            'INSERT INTO login_attempts (identifier, ip, success) VALUES (?, ?::inet, ?)',
            [mb_substr($identifier, 0, 200), $ip, $success]
        );
    }

    public function recentFailedLogins(string $identifier, string $ip, int $minutes): int
    {
        return (int) $this->db->scalar(
            'SELECT count(*) FROM login_attempts
             WHERE success = false AND created_at > now() - (?::int * interval \'1 minute\')
               AND (identifier = ? OR ip = ?::inet)',
            [$minutes, $identifier, $ip]
        );
    }

    public function cleanup(int $logDays, int $leaseDays): array
    {
        $deletedLogs = $this->db->execute(
            'DELETE FROM request_logs WHERE created_at < now() - (?::int * interval \'1 day\')',
            [$logDays]
        );
        $deletedAttempts = $this->db->execute(
            'DELETE FROM upstream_attempts WHERE created_at < now() - (?::int * interval \'1 day\')',
            [$logDays]
        );
        $deletedLeases = $this->db->execute(
            'DELETE FROM ip_leases WHERE expires_at < now() - (?::int * interval \'1 day\')',
            [$leaseDays]
        );
        $deletedRates = $this->db->execute('DELETE FROM rate_events WHERE created_at < now() - interval \'1 day\'');
        $deletedActive = $this->db->execute('DELETE FROM active_requests WHERE expires_at < now()');
        $deletedLogins = $this->db->execute('DELETE FROM login_attempts WHERE created_at < now() - interval \'30 days\'');
        return [
            'request_logs' => $deletedLogs,
            'upstream_attempts' => $deletedAttempts,
            'ip_leases' => $deletedLeases,
            'rate_events' => $deletedRates,
            'active_requests' => $deletedActive,
            'login_attempts' => $deletedLogins,
        ];
    }
}
