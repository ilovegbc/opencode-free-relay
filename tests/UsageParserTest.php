<?php

declare(strict_types=1);

namespace Relay\Tests;

use Relay\Services\UsageParser;

final class UsageParserTest extends TestCase
{
    public function run(): void
    {
        $parser = new UsageParser();
        $parser->estimateInput(['messages' => [['role' => 'user', 'content' => 'hello world']]]);
        $parser->feed("data: {\"choices\":[{\"delta\":{\"content\":\"Hel");
        $parser->feed("lo\"}}]}\n\ndata: {\"choices\":[],\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":5,");
        $parser->feed("\"total_tokens\":15,\"prompt_tokens_details\":{\"cached_tokens\":3}}}\n\ndata: [DONE]\n\n");
        $usage = $parser->finish();
        $this->assertSame(10, $usage['input_tokens'], 'usage1 input');
        $this->assertSame(5, $usage['output_tokens'], 'usage1 output');
        $this->assertSame(15, $usage['total_tokens'], 'usage1 total');
        $this->assertSame(3, $usage['cached_tokens'], 'usage1 cached');
        $this->assertSame(5, $usage['output_chars'], 'usage1 chars');

        $parser2 = new UsageParser();
        $parser2->feed("data: {\"type\":\"message_delta\",\"message\":{\"usage\":{\"input_tokens\":7,\"output_tokens\":2}}}\n\n");
        $usage2 = $parser2->finish();
        $this->assertSame(7, $usage2['input_tokens'], 'usage2 input');
        $this->assertSame(2, $usage2['output_tokens'], 'usage2 output');
        $this->assertSame(9, $usage2['total_tokens'], 'usage2 total');

        $parser3 = new UsageParser();
        $usage3 = $parser3->finish('{"usage":{"input_tokens":4,"output_tokens":6,"cache_read_input_tokens":1}}');
        $this->assertSame(4, $usage3['input_tokens'], 'usage3 input');
        $this->assertSame(6, $usage3['output_tokens'], 'usage3 output');
        $this->assertSame(10, $usage3['total_tokens'], 'usage3 total');
        $this->assertSame(1, $usage3['cached_tokens'], 'usage3 cached');

        $parser4 = new UsageParser();
        $parser4->estimateInput(['messages' => [['role' => 'user', 'content' => str_repeat('a', 400)]]]);
        $parser4->feed("data: {\"choices\":[{\"delta\":{\"content\":\"" . str_repeat('b', 40) . "\"}}]}\n\n");
        $usage4 = $parser4->finish();
        $this->assertSame(40, $usage4['output_chars'], 'Output char count');
        $this->assertSame(10, $usage4['output_tokens'], 'Fallback output estimate is chars/4');
        $this->assertTrue($usage4['input_tokens'] > 0, 'Input estimate must be present');
    }
}
