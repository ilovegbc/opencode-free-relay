<?php

declare(strict_types=1);

namespace Relay\Controllers\Admin;

use Relay\App;
use Relay\Auth\Auth;
use Relay\Controllers\Controller;
use Relay\Http\Request;
use Relay\Http\Response;
use Relay\Repositories\IpPoolRepository;
use Relay\Services\IpProbe;
use Relay\Support\Ipv6;

final class PoolController extends Controller
{
    public function index(Request $request): never
    {
        Auth::requireAdmin();
        $this->render('admin/pools', [
            'activeNav' => 'pools',
            'pools' => (new IpPoolRepository(App::db()))->all(),
            'probeUrl' => (string) App::settings()->get('ipv6.probe_url', ''),
        ], 'layouts/admin');
    }

    public function create(Request $request): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        try {
            $data = $this->payload($request);
            $pool = (new IpPoolRepository(App::db()))->create($data);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            Response::redirect('/admin/pools');
        }
        $this->audit('ip_pool.create', 'ip_pool', (string) $pool['id'], ['cidr' => $pool['cidr']]);
        flash('success', 'IPv6 pool created. Run “Probe” to verify source binding before sending traffic.');
        Response::redirect('/admin/pools');
    }

    public function update(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        try {
            (new IpPoolRepository(App::db()))->update($id, $this->payload($request));
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            Response::redirect('/admin/pools');
        }
        $this->audit('ip_pool.update', 'ip_pool', (string) $id);
        flash('success', 'Pool updated.');
        Response::redirect('/admin/pools');
    }

    public function delete(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        (new IpPoolRepository(App::db()))->delete($id);
        $this->audit('ip_pool.delete', 'ip_pool', (string) $id);
        flash('success', 'Pool deleted.');
        Response::redirect('/admin/pools');
    }

    public function test(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        $repo = new IpPoolRepository(App::db());
        $pool = $repo->find($id);
        if ($pool === null) {
            flash('error', 'Pool not found.');
            Response::redirect('/admin/pools');
        }
        $result = (new IpProbe($repo))->probe(
            (string) App::settings()->get('ipv6.probe_url', 'https://opencode.ai/inference/v1/models'),
            $pool
        );
        flash($result['ok'] ? 'success' : 'error', $result['message'] . ' Target: ' . $result['target'] . ' · ' . $result['latency_ms'] . 'ms');
        $this->audit('ip_pool.probe', 'ip_pool', (string) $id, $result);
        Response::redirect('/admin/pools');
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        $cidr = trim((string) $request->post('cidr', ''));
        $parsed = Ipv6::parseCidr($cidr);
        $name = trim((string) $request->post('name', ''));
        if ($name === '') {
            $name = $parsed['cidr'];
        }
        return [
            'name' => mb_substr($name, 0, 100),
            'cidr' => $parsed['cidr'],
            'enabled' => (string) $request->post('enabled', '0') === '1',
            'weight' => max(1, (int) $request->post('weight', 100)),
            'lease_ttl_seconds' => max(0, (int) $request->post('lease_ttl_seconds', 604800)),
            'interface_name' => trim((string) $request->post('interface_name', '')) ?: null,
            'notes' => (string) $request->post('notes', ''),
        ];
    }
}
