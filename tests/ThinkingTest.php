<?php

declare(strict_types=1);

namespace Relay\Tests;

use Relay\Services\Thinking;

final class ThinkingTest extends TestCase
{
    public function run(): void
    {
        $remote = [
            'capabilities' => [
                'reasoning' => [
                    'supported' => true,
                    'toggle' => true,
                    'effort' => ['max', 'low', 'high'],
                    'default' => ['type' => 'effort', 'value' => 'high'],
                    'budget' => ['min' => 1024],
                ],
            ],
        ];
        $thinking = Thinking::normalize($remote);
        $this->assertTrue($thinking['supported'], 'Reasoning support is detected');
        $this->assertTrue($thinking['toggle'], 'Toggle is detected');
        $this->assertSame(['max', 'low', 'high'], $thinking['efforts'], 'Upstream effort order is kept');
        $this->assertSame(['none', 'low', 'high', 'max'], $thinking['levels'], 'Levels are ordered by the effort ladder and include none');
        $this->assertSame('high', $thinking['default'], 'Default effort is flattened');
        $this->assertSame(['min' => 1024], $thinking['budget'], 'Budget is normalised');

        $plain = Thinking::normalize(['capabilities' => ['reasoning' => ['supported' => true]]]);
        $this->assertSame([], $plain['levels'], 'No effort and no toggle means no selectable levels');
        $this->assertSame([], Thinking::normalize(['capabilities' => []])['levels'], 'Missing reasoning means no levels');
        $this->assertFalse(Thinking::normalize(['capabilities' => ['reasoning' => ['supported' => false, 'effort' => ['low']]]])['supported'], 'Unsupported reasoning is not exposed');
        $this->assertSame(['none'], Thinking::normalize(['capabilities' => ['reasoning' => ['supported' => true, 'toggle' => true]]])['levels'], 'Toggle alone exposes none');

        [$base, $level] = Thinking::splitModelId('deepseek-v4.1-flash-free:high');
        $this->assertSame('deepseek-v4.1-flash-free', $base, 'Colon suffix is split from the model id');
        $this->assertSame('high', $level, 'Colon suffix level is returned');

        [$base, $level] = Thinking::splitModelId('opencode/big-pickle#max');
        $this->assertSame('opencode/big-pickle', $base, 'Hash suffix is split from the model id');
        $this->assertSame('max', $level, 'Hash suffix level is returned');

        [$base, $level] = Thinking::splitModelId('foo:bar');
        $this->assertSame('foo:bar', $base, 'Non-level suffix is left alone');
        $this->assertSame(null, $level, 'Non-level suffix yields no level');

        $this->assertSame('high', Thinking::requestedLevel(['reasoning_effort' => 'HIGH']), 'reasoning_effort is read');
        $this->assertSame('low', Thinking::requestedLevel(['reasoning' => ['effort' => 'low']]), 'reasoning.effort is read');
        $this->assertSame('none', Thinking::requestedLevel(['thinking' => ['type' => 'disabled']]), 'Disabled thinking maps to none');
        $this->assertSame('max', Thinking::requestedLevel(['thinking' => ['type' => 'enabled', 'effort' => 'max']]), 'thinking.effort is read');
        $this->assertSame('high', Thinking::requestedLevel(['thinking' => ['type' => 'enabled', 'budget_tokens' => 9000]]), 'Budget is mapped to a level');
        $this->assertSame(null, Thinking::requestedLevel([]), 'No level requested means provider default');

        $levels = Thinking::fromReasoning(['supported' => true, 'effort' => ['low', 'high', 'max']]);
        $this->assertSame('high', Thinking::resolveLevel($levels, 'medium'), 'Unknown level is clamped to the nearest supported level');
        $this->assertSame('max', Thinking::resolveLevel($levels, 'xhigh'), 'xhigh clamps to max');
        $this->assertSame('low', Thinking::resolveLevel($levels, 'none'), 'none without toggle clamps to the lowest effort');
        $this->assertSame('high', Thinking::resolveLevel($levels, 'high'), 'Supported level is kept');
        $this->assertSame(null, Thinking::resolveLevel($levels, 'auto'), 'auto keeps the provider default');
        $this->assertSame(null, Thinking::resolveLevel($levels, null), 'Missing level stays missing');

        $toggleOnly = Thinking::fromReasoning(['supported' => true, 'toggle' => true]);
        $this->assertSame('high', Thinking::resolveLevel($toggleOnly, 'high'), 'Models without effort list pass known levels through');
        $this->assertSame('none', Thinking::resolveLevel($toggleOnly, 'none'), 'Toggle-only models accept none');
        $this->assertSame(null, Thinking::resolveLevel($toggleOnly, 'huge'), 'Unknown non-ladder level is dropped');

        $this->assertSame('max', Thinking::highestLevel($levels), 'Highest declared effort is max');
        $this->assertSame('high', Thinking::highestLevel(Thinking::fromReasoning(['supported' => true, 'effort' => ['none', 'high']])), 'Highest ignores none');
        $this->assertSame(null, Thinking::highestLevel($toggleOnly), 'Toggle-only models have no highest effort');
        $this->assertSame(null, Thinking::highestLevel($plain), 'Models without reasoning have no highest effort');

        $chat = Thinking::apply(['model' => 'x'], $levels, 'chat', 'high');
        $this->assertSame('high', $chat['reasoning_effort'], 'Chat requests get reasoning_effort');
        $responses = Thinking::apply(['reasoning_effort' => 'low'], $levels, 'responses', 'max');
        $this->assertSame(['effort' => 'max'], $responses['reasoning'], 'Responses requests get reasoning.effort');
        $this->assertFalse(isset($responses['reasoning_effort']), 'Responses requests drop the chat field');
        $messages = Thinking::apply(['thinking' => ['type' => 'enabled', 'budget_tokens' => 2048]], $levels, 'messages', 'max');
        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 2048], $messages['thinking'], 'Messages requests are left untouched');
        $this->assertFalse(isset($messages['reasoning_effort']), 'Messages requests never get reasoning_effort');

        $auto = Thinking::apply(['reasoning_effort' => 'auto'], $levels, 'chat', null);
        $this->assertFalse(isset($auto['reasoning_effort']), 'auto is dropped so the upstream default applies');
        $autoResponses = Thinking::apply(['reasoning' => ['effort' => 'default', 'summary' => 'auto']], $levels, 'responses', null);
        $this->assertSame(['summary' => 'auto'], $autoResponses['reasoning'], 'default effort is dropped from responses while other reasoning fields stay');
    }
}
