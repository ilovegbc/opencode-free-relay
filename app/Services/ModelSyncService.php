<?php

declare(strict_types=1);

namespace Relay\Services;

use Relay\Repositories\IpPoolRepository;
use Relay\Repositories\ModelRepository;
use Relay\Support\Database;
use Relay\Support\Logger;
use Relay\Support\Settings;

/**
 * Refreshes the free-model catalog from OpenCode's public models endpoint.
 * Designed to run hourly from bin/cron.php (or public/cron.php with a secret).
 */
final class ModelSyncService
{
    public function __construct(
        private Database $db,
        private Settings $settings,
        private ModelRepository $models,
    ) {
    }

    /** @return array<string,mixed> */
    public function sync(): array
    {
        if (!$this->db->advisoryLock('relay:model-sync')) {
            return ['status' => 'busy', 'message' => 'Another model sync is already running.'];
        }

        $runId = null;
        try {
            $row = $this->db->fetch(
                'INSERT INTO model_sync_runs (status) VALUES (\'running\') RETURNING id'
            );
            $runId = $row !== null ? (int) $row['id'] : null;

            $payload = $this->fetchModels();
            $models = $payload['data'] ?? null;
            if (!is_array($models)) {
                throw new \RuntimeException('Models endpoint did not return a data array.');
            }

            $freeIds = [];
            $total = 0;
            $changed = 0;
            $autoEnable = $this->settings->bool('models.auto_enable_new_free', true);
            foreach ($models as $model) {
                if (!is_array($model) || !isset($model['id'])) {
                    continue;
                }
                $total++;
                $result = $this->models->upsertFromRemote($model, $autoEnable);
                if ($result['changed']) {
                    $changed++;
                }
                if (ModelRepository::isFree($model) && ModelRepository::upstreamKind($model) !== null) {
                    $freeIds[] = (string) $model['id'];
                }
            }

            if ($freeIds === []) {
                throw new \RuntimeException('The upstream returned zero usable free chat models; keeping the previous catalog.');
            }

            $this->models->markUnavailableExcept($freeIds);
            $freeCount = count(array_unique($freeIds));
            $now = gmdate('c');
            $this->settings->setMany([
                'models.last_synced_at' => $now,
                'models.last_sync_status' => 'success',
                'models.last_sync_error' => null,
            ]);

            if ($runId !== null) {
                $this->db->execute(
                    'UPDATE model_sync_runs SET finished_at = now(), status = \'success\', total_models = ?, free_models = ?, changed_models = ? WHERE id = ?',
                    [$total, $freeCount, $changed, $runId]
                );
            }
            Logger::info('Free model catalog refreshed', ['total' => $total, 'free' => $freeCount, 'changed' => $changed]);
            return ['status' => 'success', 'total' => $total, 'free' => $freeCount, 'changed' => $changed, 'last_synced_at' => $now];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $this->settings->setMany([
                'models.last_sync_status' => 'error',
                'models.last_sync_error' => $message,
            ]);
            if ($runId !== null) {
                $this->db->execute(
                    'UPDATE model_sync_runs SET finished_at = now(), status = \'error\', error = ? WHERE id = ?',
                    [mb_substr($message, 0, 2000), $runId]
                );
            }
            Logger::error('Free model catalog refresh failed', ['message' => $message]);
            return ['status' => 'error', 'message' => $message];
        } finally {
            $this->db->advisoryUnlock('relay:model-sync');
        }
    }

    /** @return array<string,mixed> */
    public function syncIfDue(?int $interval = null): array
    {
        $interval ??= max(60, $this->settings->int('models.sync_interval', 3600));
        $last = $this->settings->get('models.last_synced_at');
        if (is_string($last) && $last !== '') {
            $timestamp = strtotime($last);
            if ($timestamp !== false && time() - $timestamp < $interval) {
                return ['status' => 'skipped', 'message' => 'Model catalog is up to date.', 'last_synced_at' => $last];
            }
        }
        return $this->sync();
    }

    /** @return array<string,mixed> */
    private function fetchModels(): array
    {
        $url = $this->settings->string('upstream.models_url', 'https://opencode.ai/inference/v1/models');
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new \RuntimeException('Invalid upstream models URL.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if ($scheme !== 'https' && !($scheme === 'http' && $this->settings->bool('upstream.allow_http', false))) {
            throw new \RuntimeException('Models URL must use HTTPS.');
        }

        // The model list is also an OpenCode request. Use a fresh address from
        // the configured pool so it follows the same IPv6-only policy.
        $allocator = new IpAllocator($this->db, new IpPoolRepository($this->db), $this->settings);
        $lease = $allocator->allocate(uuid_v4());

        $version = $this->settings->string('upstream.client_version', '2.3.0');
        $channel = $this->settings->string('upstream.client_channel', 'latest');
        $client = $this->settings->string('upstream.client_name', 'cli');
        $ua = $this->settings->string('upstream.user_agent', '');
        if ($ua === '') {
            $ua = 'opencode/' . $channel . '/' . $version . '/' . $client;
        }

        $headers = [
            'Accept: application/json',
            'Accept-Encoding: identity',
            'User-Agent: ' . $ua,
            'x-opencode-client: ' . $client,
        ];
        $key = $this->settings->string('upstream.key', '');
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialise cURL for model sync.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V6,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->settings->bool('upstream.verify_tls', true),
            CURLOPT_SSL_VERIFYHOST => $this->settings->bool('upstream.verify_tls', true) ? 2 : 0,
        ]);
        $bindError = null;
        if ($this->settings->string('ipv6.bind_method', 'interface') === 'opensocket'
            && defined('CURLOPT_OPENSOCKETFUNCTION')
            && function_exists('socket_create')) {
            curl_setopt($ch, CURLOPT_OPENSOCKETFUNCTION, static function ($address, $socket) use ($lease, &$bindError) {
                $sock = @socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
                if ($sock === false) {
                    $bindError = 'socket_create failed';
                    return false;
                }
                @socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
                if (!@socket_bind($sock, $lease->address, 0)) {
                    $bindError = socket_strerror(socket_last_error($sock));
                    @socket_close($sock);
                    return false;
                }
                return $sock;
            });
        } else {
            curl_setopt($ch, CURLOPT_INTERFACE, $lease->address);
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0 || $bindError !== null) {
            $message = $bindError ?? ($error !== '' ? $error : 'curl error ' . $errno);
            $allocator->markBindFailure($lease, $message);
            throw new \RuntimeException('Model sync failed over IPv6 via ' . $lease->address . ': ' . $message);
        }
        $allocator->markSuccess($lease);
        if ($status !== 200) {
            throw new \RuntimeException('Model sync returned HTTP ' . $status . '.');
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Model sync returned invalid JSON.');
        }
        return $decoded;
    }
}
