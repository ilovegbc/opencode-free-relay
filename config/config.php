<?php

declare(strict_types=1);

use Relay\Support\Env;

return [
    'app' => [
        'name' => Env::get('APP_NAME', 'OpenCode Free Relay'),
        'env' => Env::get('APP_ENV', 'production'),
        'debug' => Env::bool('APP_DEBUG', false),
        'url' => rtrim(Env::get('APP_URL', 'http://localhost:8080'), '/'),
        'key' => Env::get('APP_KEY', ''),
        'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
        'base_path' => Env::get('APP_BASE_PATH', ''),
    ],
    'db' => [
        'url' => Env::get('DATABASE_URL'),
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '5432'),
        'name' => Env::get('DB_NAME', 'opencode_relay'),
        'user' => Env::get('DB_USER', 'relay'),
        'password' => Env::get('DB_PASSWORD', ''),
        'sslmode' => Env::get('DB_SSLMODE', 'prefer'),
    ],
    'session' => [
        'name' => Env::get('SESSION_NAME', 'ocrelay_session'),
        'lifetime' => (int) Env::get('SESSION_LIFETIME', '86400'),
        'secure' => Env::get('SESSION_SECURE', 'auto'),
        'trust_proxy' => Env::bool('TRUST_PROXY', false),
    ],
    'upstream' => [
        'base_url' => Env::get('OPENCODE_BASE_URL', 'https://opencode.ai/inference'),
        'models_url' => Env::get('OPENCODE_MODELS_URL', 'https://opencode.ai/inference/v1/models'),
        'key' => Env::get('OPENCODE_UPSTREAM_KEY', 'public'),
        'client_name' => Env::get('OPENCODE_CLIENT_NAME', 'cli'),
        'client_channel' => Env::get('OPENCODE_CLIENT_CHANNEL', 'latest'),
        'client_version' => Env::get('OPENCODE_CLIENT_VERSION', '2.3.0'),
        'user_agent' => Env::get('OPENCODE_USER_AGENT', ''),
        'max_ip_retries' => (int) Env::get('OPENCODE_MAX_IP_RETRIES', '2'),
        'connect_timeout' => (int) Env::get('OPENCODE_CONNECT_TIMEOUT', '15'),
        'timeout' => (int) Env::get('OPENCODE_TIMEOUT', '900'),
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', Env::get('OPENCODE_ALLOWED_HOSTS', 'opencode.ai'))))),
        'allow_http' => Env::bool('OPENCODE_ALLOW_HTTP', false),
        'verify_tls' => Env::bool('OPENCODE_VERIFY_TLS', true),
        'stream_include_usage' => Env::bool('OPENCODE_STREAM_INCLUDE_USAGE', true),
        'prompt_cache_key' => Env::bool('OPENCODE_PROMPT_CACHE_KEY', true),
        'mirror_client_headers' => Env::bool('OPENCODE_MIRROR_CLIENT_HEADERS', true),
    ],
    'ipv6' => [
        'required' => Env::bool('IPV6_REQUIRED', true),
        'default_lease_ttl' => (int) Env::get('IPV6_DEFAULT_LEASE_TTL', '604800'),
        'allow_loopback' => Env::bool('IPV6_ALLOW_LOOPBACK', false),
        'bind_method' => Env::get('IPV6_BIND_METHOD', 'interface'),
        'probe_url' => Env::get('IPV6_PROBE_URL', 'https://opencode.ai/inference/v1/models'),
    ],
    'cron' => [
        'secret' => Env::get('CRON_SECRET', ''),
        'model_sync_interval' => (int) Env::get('MODEL_SYNC_INTERVAL', '3600'),
        'log_retention_days' => (int) Env::get('LOG_RETENTION_DAYS', '30'),
        'lease_retention_days' => (int) Env::get('LEASE_RETENTION_DAYS', '7'),
    ],
    'defaults' => [
        'registration_mode' => Env::get('REGISTRATION_MODE', 'open'),
        'default_plan' => Env::get('DEFAULT_PLAN', 'free'),
        'require_approval' => Env::bool('REQUIRE_APPROVAL', false),
        'public_model_list' => Env::bool('PUBLIC_MODEL_LIST', true),
        'auto_enable_new_free_models' => Env::bool('AUTO_ENABLE_NEW_FREE_MODELS', true),
    ],
];
