<?php

declare(strict_types=1);

namespace Relay\Tests;

use Relay\Repositories\ModelRepository;

final class ModelFilterTest extends TestCase
{
    public function run(): void
    {
        $base = [
            'type' => 'chat',
            'name' => 'Some Model',
            'api' => [
                'chat-completions' => [
                    'base_url' => 'https://opencode.ai/inference/openai/v1',
                ],
            ],
        ];

        $freeCost = array_merge($base, ['cost' => ['text' => ['input' => ['price' => 0], 'output' => ['price' => 0]]]]);
        $paidCost = array_merge($base, ['cost' => ['text' => ['input' => ['price' => 1], 'output' => ['price' => 2]]]]);
        $freeName = array_merge($base, ['name' => 'GLM-5.3-Flash Free', 'cost' => ['text' => ['input' => ['price' => 1], 'output' => ['price' => 2]]]]);
        $bigPickle = array_merge($base, ['id' => 'big-pickle', 'name' => 'Big Pickle', 'cost' => ['text' => ['input' => ['price' => 0], 'output' => ['price' => 0]]]]);
        $nonChat = array_merge($base, ['type' => 'embedding', 'cost' => ['text' => ['input' => ['price' => 0], 'output' => ['price' => 0]]]]);
        $noApi = ['type' => 'chat', 'name' => 'Free', 'cost' => ['text' => ['input' => ['price' => 0], 'output' => ['price' => 0]]]];

        $this->assertTrue(ModelRepository::isFree($freeCost), 'Zero cost is free');
        $this->assertFalse(ModelRepository::isFree($paidCost), 'Paid model is not free');
        $this->assertTrue(ModelRepository::isFree($freeName), 'Name containing Free is free');
        $this->assertTrue(ModelRepository::isFree($bigPickle), 'Big Pickle zero-cost is free');
        $this->assertFalse(ModelRepository::isFree($nonChat), 'Non-chat is not free');

        $this->assertSame('chat', ModelRepository::upstreamKind($freeCost));
        $this->assertSame('https://opencode.ai/inference/openai/v1', ModelRepository::upstreamBaseUrl($freeCost, 'chat'));
        $this->assertSame(null, ModelRepository::upstreamKind($noApi));
        $this->assertSame('', ModelRepository::upstreamBaseUrl($noApi, null));

        $messages = [
            'type' => 'chat',
            'name' => 'Claude Free',
            'api' => ['messages' => ['base_url' => 'https://opencode.ai/inference/anthropic/v1']],
        ];
        $this->assertSame('messages', ModelRepository::upstreamKind($messages));
        $this->assertSame('https://opencode.ai/inference/anthropic/v1', ModelRepository::upstreamBaseUrl($messages, 'messages'));

        $responses = [
            'type' => 'chat',
            'name' => 'GPT Free',
            'api' => ['responses' => ['base_url' => 'https://opencode.ai/inference/openai/v1']],
        ];
        $this->assertSame('responses', ModelRepository::upstreamKind($responses));
    }
}
