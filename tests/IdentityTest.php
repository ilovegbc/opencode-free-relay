<?php

declare(strict_types=1);

namespace Relay\Tests;

use Relay\App;
use Relay\Http\Request;
use Relay\Services\IdentityResolver;
use Relay\Support\Settings;

final class IdentityTest extends TestCase
{
    public function run(): void
    {
        $settings = new Settings(App::db(), [], false);
        $resolver = new IdentityResolver($settings);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/v1/chat/completions';
        $_SERVER['REMOTE_ADDR'] = '2001:db8::1';
        $_SERVER['HTTP_USER_AGENT'] = 'test-client/1.0';

        $body = [
            'model' => 'big-pickle',
            'system' => 'You are a test.',
            'messages' => [
                ['role' => 'user', 'content' => 'first question'],
                ['role' => 'assistant', 'content' => 'answer'],
                ['role' => 'user', 'content' => 'second question'],
            ],
        ];
        $key = ['id' => 7];
        $user = ['id' => 11];

        $a = $resolver->resolve(new Request(), $body, $key, $user);
        $b = $resolver->resolve(new Request(), $body, $key, $user);
        $this->assertSame($a->affinity, $b->affinity, 'Session derivation must be stable');
        $this->assertTrue(
            (bool) preg_match('/^ses_[0-9a-f]{12}[0-9A-Za-z]{14}$/', $a->affinity),
            'Affinity must be the 30-char canonical layout'
        );
        $this->assertSame('ffe', substr($a->affinity, 13, 3), 'Canonical id low bits must render as ffe');
        $this->assertSame(substr($a->affinity, 4), $a->promptCacheKey, 'Prompt cache key is affinity without ses_');
        $this->assertSame('opencode/1.19.6 ai-sdk/provider-utils/4.0.23 runtime/bun/1.4.4', $a->userAgent);
        $this->assertSame('global', $a->projectId, 'Default project id is global');
        $headers = $a->headers();
        $this->assertSame($a->affinity, $headers['x-opencode-session']);
        $this->assertSame($a->sessionId, $headers['x-opencode-session-id']);
        $this->assertSame('cli', $headers['x-opencode-client']);
        $this->assertTrue(!isset($headers['x-session-affinity']), 'Only official headers are emitted');
        $this->contains('opencode/1.19.6', $headers['User-Agent']);

        $body2 = $body;
        $body2['messages'][0]['content'] = 'different first question';
        $c = $resolver->resolve(new Request(), $body2, $key, $user);
        $this->assertNotSame($a->affinity, $c->affinity, 'Different first user message must derive a different session');

        $affinityHex = str_repeat('a', 64);
        $_SERVER['HTTP_X_SESSION_AFFINITY'] = $affinityHex;
        $d = $resolver->resolve(new Request(), $body, $key, $user);
        $this->assertTrue(
            (bool) preg_match('/^ses_[0-9a-f]{12}[0-9A-Za-z]{14}$/', $d->affinity),
            'Legacy 64-hex affinity is canonicalised'
        );
        $this->assertNotSame('ses_' . $affinityHex, $d->affinity);

        unset($_SERVER['HTTP_X_SESSION_AFFINITY']);
        $canonical = 'ses_e10f9c71fffeap0XFMkHajoCbt';
        $_SERVER['HTTP_X_OPENCODE_SESSION_ID'] = $canonical;
        $e = $resolver->resolve(new Request(), $body, $key, $user);
        $this->assertSame($canonical, $e->sessionId, 'Canonical session ids pass through unchanged');
        $this->assertSame($canonical, $e->affinity);

        $_SERVER['HTTP_X_OPENCODE_PARENT_SESSION_ID'] = 'ses_e10f9c71fffeap0XFMkHajoCbt';
        $f = $resolver->resolve(new Request(), $body, $key, $user);
        $this->assertTrue(isset($f->headers()['x-opencode-parent-session-id']), 'Parent session header should be emitted');
        unset($_SERVER['HTTP_X_OPENCODE_PARENT_SESSION_ID'], $_SERVER['HTTP_X_OPENCODE_SESSION_ID']);
    }
}
