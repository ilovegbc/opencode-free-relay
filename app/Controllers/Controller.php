<?php

declare(strict_types=1);

namespace Relay\Controllers;

use Relay\Auth\Auth;
use Relay\Http\Request;
use Relay\Http\Response;
use Relay\Repositories\AnnouncementRepository;
use Relay\Repositories\LogRepository;
use Relay\Support\Session;
use Relay\Support\View;
use Relay\App;

abstract class Controller
{
    /** @param array<string,mixed> $data */
    protected function render(string $view, array $data = [], string $layout = 'layouts/app'): never
    {
        $user = Auth::user();
        $announcements = [];
        try {
            $announcements = (new AnnouncementRepository(App::db()))->active();
        } catch (\Throwable) {
            // Installation may be incomplete.
        }
        $data += [
            'siteName' => (string) setting('site.name', App::config('app.name', 'OpenCode Free Relay')),
            'siteTagline' => (string) setting('site.tagline', ''),
            'siteDescription' => (string) setting('site.description', ''),
            'user' => $user,
            'announcements' => $announcements,
            'flashes' => Session::consumeFlashes(),
            'activeNav' => '',
            'footer' => (string) setting('branding.footer', ''),
        ];
        $html = View::render($view, $data, $layout);
        Session::forget('_old');
        Response::html($html);
    }

    protected function validateCsrf(Request $request): void
    {
        $request->validateCsrf();
    }

    protected function requirePost(Request $request): void
    {
        if ($request->method() !== 'POST') {
            throw new \Relay\Http\HttpException(405, 'Method not allowed.', 'method_not_allowed');
        }
    }

    /** @return array{page:int,limit:int,offset:int} */
    protected function page(Request $request, int $limit = 50): array
    {
        $page = max(1, (int) ($request->query('page', 1)));
        return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
    }

    /** @param array<string,mixed> $context */
    protected function audit(string $action, string $targetType = '', string $targetId = '', array $context = []): void
    {
        $user = Auth::user();
        try {
            (new LogRepository(App::db()))->audit(
                $user ? (int) $user['id'] : null,
                $action,
                $targetType,
                $targetId,
                $context,
                $_SERVER['REMOTE_ADDR'] ?? null
            );
        } catch (\Throwable) {
            // Audit failure must not break the admin action.
        }
    }

    /** @return array{0:int,1:int} */
    protected function paginationMeta(int $total, array $page): array
    {
        $pages = max(1, (int) ceil($total / max(1, (int) $page['limit'])));
        return [$page['page'], $pages];
    }
}
