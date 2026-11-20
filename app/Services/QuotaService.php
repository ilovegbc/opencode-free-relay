<?php

declare(strict_types=1);

namespace Relay\Services;

use DateTimeImmutable;
use DateTimeZone;
use Relay\Http\QuotaException;
use Relay\Repositories\ApiKeyRepository;
use Relay\Support\Database;
use Relay\Support\Settings;

final class QuotaService
{
    public function __construct(
        private Database $db,
        private Settings $settings,
        private ApiKeyRepository $keys,
    ) {
    }

    /**
     * Reserve one request before contacting upstream. Locking usage_daily makes
     * the check atomic per user, so concurrent requests cannot overshoot the
     * daily request/token ceiling.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $key
     */
    public function begin(array $user, array $key, string $requestId, string $endpoint): Reservation
    {
        $userId = (int) $user['id'];
        $keyId = (int) $key['id'];
        $concurrency = max(1, (int) ($user['concurrency'] ?? 1));
        $rpm = max(0, (int) ($user['rpm'] ?? 0));
        $dailyRequests = (int) ($user['daily_requests'] ?? 0);
        $dailyTokens = (int) ($user['daily_tokens'] ?? 0);
        $day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

        return $this->db->transaction(function () use ($userId, $keyId, $requestId, $endpoint, $concurrency, $rpm, $dailyRequests, $dailyTokens, $day): Reservation {
            $this->db->execute('DELETE FROM active_requests WHERE expires_at < now()');

            $active = (int) $this->db->scalar('SELECT count(*) FROM active_requests WHERE user_id = ?', [$userId]);
            if ($active >= $concurrency) {
                throw new QuotaException('Too many concurrent requests for this account.', 'concurrency_limit', 2);
            }

            if ($rpm === 0) {
                throw new QuotaException('This account is not allowed to make API requests.', 'rate_limit');
            }
            $recent = (int) $this->db->scalar(
                'SELECT count(*) FROM rate_events WHERE user_id = ? AND created_at > now() - interval \'60 seconds\'',
                [$userId]
            );
            if ($recent >= $rpm) {
                throw new QuotaException('Rate limit exceeded. Try again shortly.', 'rate_limit', 60);
            }
            $this->db->execute('INSERT INTO rate_events (user_id, api_key_id) VALUES (?, ?)', [$userId, $keyId]);

            $this->db->execute(
                'INSERT INTO usage_daily (user_id, day) VALUES (?, ?::date) ON CONFLICT (user_id, day) DO NOTHING',
                [$userId, $day]
            );
            $usage = $this->db->fetch(
                'SELECT * FROM usage_daily WHERE user_id = ? AND day = ?::date FOR UPDATE',
                [$userId, $day]
            );
            if ($usage === null) {
                throw new \RuntimeException('Could not open usage bucket.');
            }

            $requests = (int) $usage['requests'] + (int) $usage['reserved_requests'];
            if ($dailyRequests >= 0 && $requests >= $dailyRequests) {
                throw new QuotaException('Daily request quota reached. It resets at 00:00 UTC.', 'daily_request_limit');
            }
            $tokens = (int) $usage['input_tokens'] + (int) $usage['output_tokens'];
            if ($dailyTokens >= 0 && $tokens >= $dailyTokens) {
                throw new QuotaException('Daily token quota reached. It resets at 00:00 UTC.', 'daily_token_limit');
            }

            $this->db->execute(
                'UPDATE usage_daily SET reserved_requests = reserved_requests + 1, updated_at = now()
                 WHERE user_id = ? AND day = ?::date',
                [$userId, $day]
            );
            $this->db->execute(
                'INSERT INTO active_requests (request_id, user_id, api_key_id, endpoint, expires_at)
                 VALUES (?::uuid, ?, ?, ?, now() + interval \'15 minutes\')',
                [$requestId, $userId, $keyId, $endpoint]
            );

            return new Reservation($requestId, $userId, $keyId, $day, $dailyRequests, $dailyTokens);
        });
    }

    /** @param array<string,mixed> $usage */
    public function commit(Reservation $reservation, array $usage): void
    {
        $input = max(0, (int) ($usage['input_tokens'] ?? 0));
        $output = max(0, (int) ($usage['output_tokens'] ?? 0));
        $total = max(0, (int) ($usage['total_tokens'] ?? ($input + $output)));
        $cached = max(0, (int) ($usage['cached_tokens'] ?? 0));

        $this->db->transaction(function () use ($reservation, $input, $output, $cached): void {
            $this->db->execute(
                'UPDATE usage_daily SET
                    reserved_requests = GREATEST(0, reserved_requests - 1),
                    requests = requests + 1,
                    input_tokens = input_tokens + ?,
                    output_tokens = output_tokens + ?,
                    updated_at = now()
                 WHERE user_id = ? AND day = ?::date',
                [$input, $output, $reservation->userId, $reservation->day]
            );
            $this->db->execute(
                'UPDATE users SET total_requests = total_requests + 1,
                    total_input_tokens = total_input_tokens + ?,
                    total_output_tokens = total_output_tokens + ?
                 WHERE id = ?',
                [$input, $output, $reservation->userId]
            );
            $this->db->execute('DELETE FROM active_requests WHERE request_id = ?::uuid', [$reservation->requestId]);
            $this->db->execute('UPDATE api_keys SET last_used_at = now(), request_count = request_count + 1 WHERE id = ?', [$reservation->apiKeyId]);
        });
    }

    public function fail(Reservation $reservation): void
    {
        try {
            $this->db->transaction(function () use ($reservation): void {
                $this->db->execute(
                    'UPDATE usage_daily SET reserved_requests = GREATEST(0, reserved_requests - 1), updated_at = now()
                     WHERE user_id = ? AND day = ?::date',
                    [$reservation->userId, $reservation->day]
                );
                $this->db->execute('DELETE FROM active_requests WHERE request_id = ?::uuid', [$reservation->requestId]);
            });
        } catch (\Throwable) {
            // Logging must continue even if quota cleanup fails.
        }
    }

    /** @return array<string,mixed> */
    public function today(int $userId): array
    {
        $row = $this->db->fetch(
            'SELECT * FROM usage_daily WHERE user_id = ? AND day = current_date',
            [$userId]
        );
        return $row ?? [
            'user_id' => $userId,
            'day' => gmdate('Y-m-d'),
            'requests' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'reserved_requests' => 0,
        ];
    }

    public function verifyKeyStillActive(int $keyId): bool
    {
        return $this->keys->find($keyId)['status'] === 'active';
    }
}
