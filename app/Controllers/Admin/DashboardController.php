<?php

declare(strict_types=1);

namespace Relay\Controllers\Admin;

use Relay\App;
use Relay\Auth\Auth;
use Relay\Controllers\Controller;
use Relay\Http\Request;
use Relay\Http\Response;
use Relay\Http\ValidationException;
use Relay\Repositories\AnnouncementRepository;
use Relay\Repositories\IpPoolRepository;
use Relay\Repositories\LogRepository;
use Relay\Repositories\ModelRepository;
use Relay\Repositories\PlanRepository;
use Relay\Repositories\UserRepository;
use Relay\Support\Ipv6;

final class DashboardController extends Controller
{
    public function index(Request $request): never
    {
        Auth::requireAdmin();
        $db = App::db();
        $this->render('admin/dashboard', [
            'activeNav' => 'admin',
            'userStats' => (new UserRepository($db))->summary(),
            'requestStats' => (new LogRepository($db))->dashboard(),
            'poolStats' => (new IpPoolRepository($db))->summary(),
            'freeModels' => (new ModelRepository($db))->countFree(),
            'lastSync' => (new ModelRepository($db))->lastSync(),
            'syncRuns' => (new ModelRepository($db))->syncRuns(8),
            'series' => (new LogRepository($db))->dailySeries(14),
            'announcements' => (new AnnouncementRepository($db))->all(),
        ], 'layouts/admin');
    }

    public function settings(Request $request): never
    {
        Auth::requireAdmin();
        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            $types = $this->settingTypes();
            $values = [];
            foreach ($types as $key => $type) {
                $value = $request->post($key);
                if ($type === 'bool') {
                    $values[$key] = $value === '1' || $value === 'on' || $value === 'true';
                } elseif ($type === 'int') {
                    if ($value === '' || !is_numeric($value)) {
                        throw new ValidationException('Setting ' . $key . ' must be a number.');
                    }
                    $values[$key] = (int) $value;
                } else {
                    $values[$key] = is_string($value) ? trim($value) : '';
                }
            }
            if (!in_array($values['site.registration_mode'] ?? 'open', ['open', 'invite', 'closed'], true)) {
                throw new ValidationException('Registration mode must be open, invite or closed.');
            }
            if (!in_array($values['site.default_plan'] ?? '', array_column((new PlanRepository(App::db()))->all(), 'code'), true)) {
                throw new ValidationException('Default plan does not exist.');
            }
            if (!in_array($values['ipv6.bind_method'] ?? 'interface', ['interface', 'opensocket'], true)) {
                throw new ValidationException('IPv6 bind method must be interface or opensocket.');
            }
            App::settings()->setMany($values);
            $this->audit('settings.update', 'settings', 'global', ['keys' => array_keys($values)]);
            flash('success', 'Settings saved.');
            Response::redirect('/admin/settings');
        }

        $this->render('admin/settings', [
            'activeNav' => 'settings',
            'settings' => App::settings()->all(),
            'plans' => (new PlanRepository(App::db()))->all(),
        ], 'layouts/admin');
    }

    public function announcements(Request $request): never
    {
        Auth::requireAdmin();
        $repo = new AnnouncementRepository(App::db());
        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            $title = trim((string) $request->post('title', ''));
            if ($title === '') {
                throw new ValidationException('Announcement title is required.');
            }
            $data = [
                'title' => $title,
                'body' => (string) $request->post('body', ''),
                'level' => in_array($request->post('level'), ['info', 'success', 'warning', 'danger'], true) ? (string) $request->post('level') : 'info',
                'active' => (string) $request->post('active', '0') === '1',
                'starts_at' => $this->dateOrNull($request->post('starts_at')),
                'ends_at' => $this->dateOrNull($request->post('ends_at')),
            ];
            $id = (int) $request->post('id', 0);
            if ($id > 0) {
                $repo->update($id, $data);
                $this->audit('announcement.update', 'announcement', (string) $id);
            } else {
                $repo->create($data);
                $this->audit('announcement.create', 'announcement', $title);
            }
            flash('success', 'Announcement saved.');
            Response::redirect('/admin/announcements');
        }
        $this->render('admin/announcements', [
            'activeNav' => 'announcements',
            'announcements' => $repo->all(),
        ], 'layouts/admin');
    }

    public function deleteAnnouncement(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        (new AnnouncementRepository(App::db()))->delete($id);
        $this->audit('announcement.delete', 'announcement', (string) $id);
        flash('success', 'Announcement deleted.');
        Response::redirect('/admin/announcements');
    }

    public function diagnostics(Request $request): never
    {
        Auth::requireAdmin();
        $checks = [];
        $checks[] = ['name' => 'PHP >= 8.1', 'ok' => PHP_VERSION_ID >= 80100, 'detail' => PHP_VERSION];
        foreach (['pdo_pgsql', 'curl', 'openssl', 'mbstring', 'json'] as $extension) {
            $checks[] = ['name' => 'PHP extension: ' . $extension, 'ok' => extension_loaded($extension), 'detail' => extension_loaded($extension) ? 'loaded' : 'missing'];
        }
        $checks[] = ['name' => 'PHP extension: sockets (optional)', 'ok' => extension_loaded('sockets'), 'detail' => extension_loaded('sockets') ? 'loaded' : 'optional; only needed for opensocket bind method'];
        $checks[] = ['name' => 'PHP extension: bcmath (optional)', 'ok' => extension_loaded('bcmath'), 'detail' => extension_loaded('bcmath') ? 'loaded' : 'optional; exact capacity checks use it'];
        $appKey = (string) App::config('app.key', '');
        $checks[] = ['name' => 'APP_KEY configured', 'ok' => strlen($appKey) >= 32 && !str_contains($appKey, 'change-me'), 'detail' => strlen($appKey) >= 32 ? 'present' : 'too short or placeholder'];
        $checks[] = ['name' => 'PostgreSQL', 'ok' => App::db()->healthy(), 'detail' => App::db()->healthy() ? 'connected' : 'connection failed'];

        $pools = [];
        $models = 0;
        $lastSync = null;
        try {
            $pools = (new IpPoolRepository(App::db()))->all();
            $models = (new ModelRepository(App::db()))->countFree();
            $lastSync = (new ModelRepository(App::db()))->lastSync();
        } catch (\Throwable) {
        }
        $enabledPools = array_values(array_filter($pools, static fn (array $p): bool => (bool) $p['enabled']));
        $checks[] = ['name' => 'Enabled IPv6 pools', 'ok' => $enabledPools !== [], 'detail' => count($enabledPools) . ' enabled / ' . count($pools) . ' total'];
        $checks[] = ['name' => 'Free models loaded', 'ok' => $models > 0, 'detail' => $models . ' models'];
        $lastSyncAt = $lastSync['finished_at'] ?? null;
        $checks[] = ['name' => 'Model sync is recent', 'ok' => $lastSyncAt !== null && strtotime((string) $lastSyncAt) > time() - 7200, 'detail' => $lastSyncAt ?? 'never'];

        $aaaa = @dns_get_record('opencode.ai', DNS_AAAA);
        $checks[] = ['name' => 'opencode.ai has AAAA', 'ok' => is_array($aaaa) && $aaaa !== [], 'detail' => is_array($aaaa) && $aaaa !== [] ? ($aaaa[0]['ipv6'] ?? 'yes') : 'no AAAA record or DNS failed'];
        $sysctl = @file_get_contents('/proc/sys/net/ipv6/ip_nonlocal_bind');
        $checks[] = ['name' => 'net.ipv6.ip_nonlocal_bind', 'ok' => $sysctl === false ? false : trim($sysctl) === '1', 'detail' => $sysctl === false ? 'not readable (normal on Windows/containers)' : trim($sysctl)];

        $poolDetails = [];
        foreach ($pools as $pool) {
            try {
                $parsed = Ipv6::parseCidr((string) $pool['cidr']);
                $poolDetails[] = $pool + [
                    'host_bits' => Ipv6::hostBits($parsed['prefix']),
                    'capacity' => Ipv6::capacity($parsed['prefix']),
                ];
            } catch (\Throwable $e) {
                $poolDetails[] = $pool + ['host_bits' => '?', 'capacity' => '?'];
            }
        }

        $this->render('admin/diagnostics', [
            'activeNav' => 'diagnostics',
            'checks' => $checks,
            'pools' => $poolDetails,
            'probeUrl' => (string) App::settings()->get('ipv6.probe_url', ''),
        ], 'layouts/admin');
    }

    public function probePool(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        $pool = (new IpPoolRepository(App::db()))->find($id);
        if ($pool === null) {
            flash('error', 'Pool not found.');
            Response::redirect('/admin/diagnostics');
        }
        $result = (new \Relay\Services\IpProbe(new IpPoolRepository(App::db())))->probe(
            (string) App::settings()->get('ipv6.probe_url', 'https://opencode.ai/inference/v1/models'),
            $pool
        );
        flash($result['ok'] ? 'success' : 'error', $result['message'] . ' Target: ' . $result['target'] . ' · ' . $result['latency_ms'] . 'ms');
        $this->audit('ip_pool.probe', 'ip_pool', (string) $id, $result);
        Response::redirect('/admin/diagnostics');
    }

    /** @return array<string,string> */
    private function settingTypes(): array
    {
        return [
            'site.name' => 'string', 'site.tagline' => 'string', 'site.description' => 'string',
            'site.registration_mode' => 'string', 'site.require_approval' => 'bool', 'site.public_model_list' => 'bool',
            'site.default_plan' => 'string', 'site.welcome_credits' => 'int',
            'upstream.base_url' => 'string', 'upstream.models_url' => 'string', 'upstream.key' => 'string',
            'upstream.client_name' => 'string', 'upstream.client_channel' => 'string', 'upstream.client_version' => 'string',
            'upstream.user_agent' => 'string', 'upstream.max_ip_retries' => 'int', 'upstream.connect_timeout' => 'int',
            'upstream.timeout' => 'int', 'upstream.stream_include_usage' => 'bool', 'upstream.prompt_cache_key' => 'bool',
            'upstream.mirror_client_headers' => 'bool', 'upstream.allow_http' => 'bool', 'upstream.verify_tls' => 'bool',
            'upstream.allowed_hosts' => 'string',
            'models.auto_enable_new_free' => 'bool', 'models.sync_interval' => 'int',
            'ipv6.default_lease_ttl' => 'int', 'ipv6.allow_loopback' => 'bool',
            'ipv6.probe_url' => 'string', 'ipv6.bind_method' => 'string',
            'security.cors_origin' => 'string', 'security.login_max_attempts' => 'int', 'security.login_window_minutes' => 'int',
            'security.max_keys_per_user' => 'int',
            'billing.credits_per_request_default' => 'string', 'billing.credits_per_1k_input_default' => 'string',
            'billing.credits_per_1k_output_default' => 'string', 'billing.min_balance_to_use' => 'string',
            'logging.log_retention_days' => 'int', 'logging.lease_retention_days' => 'int', 'logging.log_prompts' => 'bool',
            'branding.footer' => 'string',
        ];
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new ValidationException('Invalid date: ' . $value);
        }
        return gmdate('Y-m-d H:i:s+00:00', $timestamp);
    }
}
