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
        $this->assertTrue((bool) preg_match('/^ses_[0-9a-f]{64}$/', $a->affinity), 'Affinity must be ses_ + 64 hex');
        $this->assertSame(substr($a->affinity, 4), $a->promptCacheKey, 'Prompt cache key is affinity without ses_');
        $this->assertSame('opencode/latest/2.3.0/cli', $a->userAgent);
        $headers = $a->headers();
        $this->assertSame($a->affinity, $headers['x-session-affinity']);
        $this->assertSame($a->affinity, $headers['X-Session-Id']);
        $this->assertSame($a->affinity, $headers['x-opencode-session']);
        $this->assertSame($a->sessionId, $headers['x-opencode-session-id']);
        $this->assertSame('cli', $headers['x-opencode-client']);
        $this->contains('opencode/latest/2.3.0/cli', $headers['User-Agent']);

        $body2 = $body;
        $body2['messages'][0]['content'] = 'different first question';
        $c = $resolver->resolve(new Request(), $body2, $key, $user);
        $this->assertNotSame($a->affinity, $c->affinity, 'Different first user message must derive a different session');

        $affinityHex = str_repeat('a', 64);
        $_SERVER['HTTP_X_SESSION_AFFINITY'] = $affinityHex;
        $d = $resolver->resolve(new Request(), $body, $key, $user);
        $this->assertSame('ses_' . $affinityHex, $d->affinity);
        $this->assertSame($affinityHex, $d->promptCacheKey);
        $this->assertSame('ses_' . $affinityHex, $d->sessionId);

        unset($_SERVER['HTTP_X_SESSION_AFFINITY']);
        $_SERVER['HTTP_X_OPENCODE_SESSION_ID'] = 'ses_' . str_repeat('b', 64);
        $e = $resolver->resolve(new Request(), $body, $key, $user);
        $this->assertSame('ses_' . str_repeat('b', 64), $e->sessionId, 'x-opencode-session-id should be preserved');

        $_SERVER['HTTP_X_OPENCODE_PARENT_SESSION_ID'] = 'ses_parent';
        $f = $resolver->resolve(new Request(), $body, $key, $user);
        $this->assertTrue(isset($f->headers()['x-opencode-parent-session-id']), 'Parent session header should be emitted');
        unset($_SERVER['HTTP_X_OPENCODE_PARENT_SESSION_ID'], $_SERVER['HTTP_X_OPENCODE_SESSION_ID']);

        $shortId = 'ses_' . str_repeat('c', 26);
        $_SERVER['HTTP_X_OPENCODE_SESSION_ID'] = $shortId;
        $g = $resolver->resolve(new Request(), $body, $key, $user);
        $this->assertSame($shortId, $g->sessionId, 'Official 26-char session ID should be preserved');
        $this->assertSame($shortId, $g->promptCacheKey, 'Official short session passes through as prompt cache key');
        unset($_SERVER['HTTP_X_OPENCODE_SESSION_ID']);
    }
}
