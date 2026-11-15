<?php

declare(strict_types=1);

namespace Relay\Services;

/**
 * Extracts token usage from OpenAI-compatible SSE streams, Anthropic streams
 * and Responses API events without storing prompt or completion text.
 */
final class UsageParser
{
    private string $buffer = '';
    private int $outputChars = 0;
    private int $inputTokens = 0;
    private int $outputTokens = 0;
    private int $totalTokens = 0;
    private int $cachedTokens = 0;
    private bool $sawUsage = false;
    private bool $sawInputUsage = false;
    private bool $sawOutputUsage = false;
    private bool $sawTotalUsage = false;
    private ?string $streamError = null;

    public function feed(string $chunk): void
    {
        $this->buffer .= $chunk;
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);
            $this->line(rtrim($line, "\r"));
        }
        // Guard against a malicious/never-ending single line.
        if (strlen($this->buffer) > 8_000_000) {
            $this->buffer = '';
        }
    }

    public function finish(?string $nonStreamBody = null): array
    {
        if ($this->buffer !== '') {
            $this->line(rtrim($this->buffer, "\r"));
            $this->buffer = '';
        }
        if ($nonStreamBody !== null && $nonStreamBody !== '') {
            $decoded = json_decode($nonStreamBody, true);
            if (is_array($decoded)) {
                $this->consume($decoded);
            }
        }
        $input = $this->inputTokens;
        $output = $this->outputTokens;
        if (!$this->sawOutputUsage) {
            $output = max($output, (int) ceil($this->outputChars / 4));
        }
        if (!$this->sawTotalUsage || $this->totalTokens <= 0) {
            $this->totalTokens = $input + $output;
        }
        return [
            'input_tokens' => max(0, $input),
            'output_tokens' => max(0, $output),
            'total_tokens' => max(0, $this->totalTokens),
            'cached_tokens' => max(0, $this->cachedTokens),
            'output_chars' => $this->outputChars,
            'stream_error' => $this->streamError,
        ];
    }

    public function estimateInput(array $body): void
    {
        $source = [
            'system' => $body['system'] ?? $body['instructions'] ?? '',
            'messages' => $body['messages'] ?? $body['input'] ?? [],
            'tools' => $body['tools'] ?? [],
        ];
        $json = json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $chars = is_string($json) ? mb_strlen($json) : 0;
        $this->inputTokens = max(1, (int) ceil($chars / 4));
    }

    private function line(string $line): void
    {
        $line = trim($line);
        if ($line === '' || !str_starts_with($line, 'data:')) {
            return;
        }
        $data = trim(substr($line, 5));
        if ($data === '' || $data === '[DONE]') {
            return;
        }
        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return;
        }
        $this->consume($decoded);
    }

    private function consume(array $event): void
    {
        if (isset($event['error'])) {
            $error = $event['error'];
            $this->streamError = is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error;
        }

        // Usage can be top-level, under message, or under response.
        $usage = null;
        if (isset($event['usage']) && is_array($event['usage'])) {
            $usage = $event['usage'];
        } elseif (isset($event['message']['usage']) && is_array($event['message']['usage'])) {
            $usage = $event['message']['usage'];
        } elseif (isset($event['response']['usage']) && is_array($event['response']['usage'])) {
            $usage = $event['response']['usage'];
        }
        if (is_array($usage)) {
            $this->mergeUsage($usage);
        }

        // OpenAI chat chunks.
        $delta = $event['choices'][0]['delta'] ?? null;
        if (is_array($delta)) {
            $this->content($delta['content'] ?? null);
            $this->content($delta['reasoning_content'] ?? null);
            if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $toolCall) {
                    $this->content($toolCall['function']['arguments'] ?? null);
                }
            }
        }
        $messageContent = $event['choices'][0]['message']['content'] ?? null;
        if ($messageContent !== null) {
            $this->content($messageContent);
        }

        // Anthropic content_block_delta.
        $this->content($event['delta']['text'] ?? null);
        $this->content($event['content_block']['text'] ?? null);

        // Responses API output_text delta.
        if (($event['type'] ?? '') === 'response.output_text.delta') {
            $this->content($event['delta'] ?? null);
        }
        if (isset($event['delta']) && is_string($event['delta']) && str_contains((string) ($event['type'] ?? ''), 'text')) {
            $this->content($event['delta']);
        }
    }

    private function content(mixed $content): void
    {
        if (is_string($content)) {
            $this->outputChars += mb_strlen($content);
            return;
        }
        if (is_array($content)) {
            foreach ($content as $part) {
                if (is_string($part)) {
                    $this->outputChars += mb_strlen($part);
                } elseif (is_array($part)) {
                    $text = $part['text'] ?? $part['content'] ?? null;
                    if (is_string($text)) {
                        $this->outputChars += mb_strlen($text);
                    }
                }
            }
        }
    }

    private function mergeUsage(array $usage): void
    {
        $this->sawUsage = true;
        $input = $usage['prompt_tokens']
            ?? $usage['input_tokens']
            ?? $usage['inputTokens']
            ?? null;
        $output = $usage['completion_tokens']
            ?? $usage['output_tokens']
            ?? $usage['outputTokens']
            ?? null;
        $total = $usage['total_tokens']
            ?? $usage['totalTokens']
            ?? null;
        $cached = $usage['prompt_tokens_details']['cached_tokens']
            ?? $usage['cache_read_input_tokens']
            ?? $usage['cached_tokens']
            ?? null;

        if (is_numeric($input)) {
            $this->inputTokens = max(0, (int) $input);
            $this->sawInputUsage = true;
        }
        if (is_numeric($output)) {
            $this->outputTokens = max(0, (int) $output);
            $this->sawOutputUsage = true;
        }
        if (is_numeric($total)) {
            $this->totalTokens = max(0, (int) $total);
            $this->sawTotalUsage = true;
        }
        if (is_numeric($cached)) {
            $this->cachedTokens = max(0, (int) $cached);
        }
    }
}
