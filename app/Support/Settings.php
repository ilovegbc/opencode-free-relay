<?php

declare(strict_types=1);

namespace Relay\Support;

use PDOException;

final class Settings
{
    /** @var array<string,mixed> */
    private array $values = [];

    private bool $loaded = false;

    /** @var array<string,mixed> */
    private const DEFAULTS = [
        'site.name' => 'OpenCode Free Relay',
        'site.tagline' => '兼容 OpenAI 的 OpenCode 免费模型中转，按请求轮换 IPv6 出口。',
        'site.description' => '自托管 OpenCode 免费模型中转站。每次上游请求都会分配新的 IPv6 地址，绝不回退 IPv4。',
        'site.registration_mode' => 'open', // open | invite | closed
        'site.require_approval' => false,
        'site.public_model_list' => true,
        'site.default_plan' => 'free',
        'site.welcome_credits' => 0,
        'site.announcement_enabled' => true,
        'upstream.base_url' => 'https://opencode.ai/inference',
        'upstream.models_url' => 'https://opencode.ai/inference/v1/models',
        'upstream.key' => 'public',
        'upstream.client_name' => 'cli',
        'upstream.client_channel' => 'latest',
        'upstream.client_version' => '2.3.0',
        'upstream.user_agent' => '',
        'upstream.max_ip_retries' => 2,
        'upstream.connect_timeout' => 15,
        'upstream.timeout' => 900,
        'upstream.stream_include_usage' => true,
        'upstream.prompt_cache_key' => true,
        'upstream.mirror_client_headers' => true,
        'upstream.allow_http' => false,
        'upstream.verify_tls' => true,
        'upstream.allowed_hosts' => 'opencode.ai',
        'models.auto_enable_new_free' => true,
        'models.sync_interval' => 3600,
        'models.last_synced_at' => null,
        'models.last_sync_status' => 'never',
        'models.last_sync_error' => null,
        'ipv6.required' => true,
        'ipv6.default_lease_ttl' => 604800,
        'ipv6.allow_loopback' => false,
        'ipv6.probe_url' => 'https://opencode.ai/inference/v1/models',
        'ipv6.bind_method' => 'interface', // interface | opensocket
        'security.cors_origin' => '*',
        'billing.credits_per_request_default' => 0,
        'billing.credits_per_1k_input_default' => 0,
        'billing.credits_per_1k_output_default' => 0,
        'billing.min_balance_to_use' => 0,
        'security.allow_registration' => true,
        'security.login_max_attempts' => 10,
        'security.login_window_minutes' => 15,
        'security.api_rate_limit_default' => 60,
        'security.api_concurrency_default' => 4,
        'security.max_keys_per_user' => 10,
        'logging.log_prompts' => false,
        'logging.log_retention_days' => 30,
        'logging.lease_retention_days' => 7,
        'cron.secret' => '',
        'branding.footer' => 'OpenCode Free Relay · MIT 开源 · IPv6 only',
    ];

    /**
     * @param array<string,mixed> $configDefaults
     * @param array<string,mixed> $seedValues test/bootstrap overrides
     */
    public function __construct(private Database $db, private array $configDefaults = [], bool $load = true, array $seedValues = [])
    {
        $this->mergeDefaults();
        $this->values = $seedValues;
        if ($load) {
            $this->load();
        } else {
            $this->loaded = true;
        }
    }

    private function mergeDefaults(): void
    {
        $config = $this->configDefaults;
        $app = is_array($config['app'] ?? null) ? $config['app'] : [];
        $upstream = is_array($config['upstream'] ?? null) ? $config['upstream'] : [];
        $ipv6 = is_array($config['ipv6'] ?? null) ? $config['ipv6'] : [];
        $cron = is_array($config['cron'] ?? null) ? $config['cron'] : [];
        $defaults = is_array($config['defaults'] ?? null) ? $config['defaults'] : [];

        $map = [
            'site.name' => $app['name'] ?? null,
            'site.registration_mode' => $defaults['registration_mode'] ?? null,
            'site.default_plan' => $defaults['default_plan'] ?? null,
            'site.require_approval' => $defaults['require_approval'] ?? null,
            'site.public_model_list' => $defaults['public_model_list'] ?? null,
            'models.auto_enable_new_free' => $defaults['auto_enable_new_free_models'] ?? null,
            'models.sync_interval' => $cron['model_sync_interval'] ?? null,
            'upstream.base_url' => $upstream['base_url'] ?? null,
            'upstream.models_url' => $upstream['models_url'] ?? null,
            'upstream.key' => $upstream['key'] ?? null,
            'upstream.client_name' => $upstream['client_name'] ?? null,
            'upstream.client_channel' => $upstream['client_channel'] ?? null,
            'upstream.client_version' => $upstream['client_version'] ?? null,
            'upstream.user_agent' => $upstream['user_agent'] ?? null,
            'upstream.max_ip_retries' => $upstream['max_ip_retries'] ?? null,
            'upstream.connect_timeout' => $upstream['connect_timeout'] ?? null,
            'upstream.timeout' => $upstream['timeout'] ?? null,
            'upstream.stream_include_usage' => $upstream['stream_include_usage'] ?? null,
            'upstream.prompt_cache_key' => $upstream['prompt_cache_key'] ?? null,
            'upstream.mirror_client_headers' => $upstream['mirror_client_headers'] ?? null,
            'upstream.allow_http' => $upstream['allow_http'] ?? null,
            'upstream.verify_tls' => $upstream['verify_tls'] ?? null,
            'upstream.allowed_hosts' => isset($upstream['allowed_hosts']) && is_array($upstream['allowed_hosts'])
                ? implode(',', $upstream['allowed_hosts'])
                : null,
            'ipv6.required' => $ipv6['required'] ?? null,
            'ipv6.default_lease_ttl' => $ipv6['default_lease_ttl'] ?? null,
            'ipv6.allow_loopback' => $ipv6['allow_loopback'] ?? null,
            'ipv6.bind_method' => $ipv6['bind_method'] ?? null,
            'ipv6.probe_url' => $ipv6['probe_url'] ?? null,
            'cron.secret' => $cron['secret'] ?? null,
            'logging.log_retention_days' => $cron['log_retention_days'] ?? null,
            'logging.lease_retention_days' => $cron['lease_retention_days'] ?? null,
        ];
        foreach ($map as $settingKey => $value) {
            if ($value !== null) {
                self::$defaultValues[$settingKey] = $value;
            }
        }
    }

    /** @var array<string,mixed> */
    private static array $defaultValues = [];

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        try {
            $rows = $this->db->fetchAll('SELECT key, value FROM settings');
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row['value'], true);
                $this->values[(string) $row['key']] = $decoded;
            }
        } catch (PDOException) {
            // Database may not be migrated yet (installer / doctor).
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }
        if (array_key_exists($key, self::$defaultValues)) {
            return self::$defaultValues[$key];
        }
        if (array_key_exists($key, self::DEFAULTS)) {
            return self::DEFAULTS[$key];
        }
        return $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (?, ?::jsonb, now())
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()'
        );
        $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        $this->values[$key] = $value;
    }

    /** @param array<string,mixed> $values */
    public function setMany(array $values): void
    {
        $this->db->transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                $this->set((string) $key, $value);
            }
        });
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge(self::DEFAULTS, self::$defaultValues, $this->values);
    }

    /** @return array<string,mixed> */
    public static function editableDefaults(): array
    {
        return self::DEFAULTS;
    }
}
