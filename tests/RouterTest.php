<?php

declare(strict_types=1);

namespace Relay\Tests;

use Relay\Http\HttpException;
use Relay\Http\Request;
use Relay\Http\Router;

final class RouterTest extends TestCase
{
    public function run(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/foo/abc';
        $router = new Router();
        $router->get('/foo/{id}', static fn (Request $request, array $params): string => $params['id'] ?? 'missing');
        $this->assertSame('abc', $router->dispatch(new Request()));

        $_SERVER['REQUEST_URI'] = '/foo/abc/';
        $this->assertSame('abc', $router->dispatch(new Request()), 'Trailing slash should normalize');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertThrows(HttpException::class, static fn () => $router->dispatch(new Request()), 'Wrong method should be 405');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/nope';
        $this->assertThrows(HttpException::class, static fn () => $router->dispatch(new Request()), 'Unknown route should be 404');

        $router2 = new Router();
        $router2->get('/files/{path}', static fn (Request $request, array $params): string => $params['path']);
        $_SERVER['REQUEST_URI'] = '/files/encoded%2Fslash';
        $this->assertSame('encoded/slash', $router2->dispatch(new Request()), 'Route params should be URL-decoded');
    }
}
