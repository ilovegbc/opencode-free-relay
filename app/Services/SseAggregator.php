<?php

declare(strict_types=1);

namespace Relay\Services;

/**
 * 把上游的 SSE 流重组为一个非流式 chat.completion 响应。
 *
 * 免费通道强制要求 streaming，非流式调用方必须由网关自行重组，
 * 否则上游会以 429 FreeUsageLimitError 拒绝 `stream:false`。
 */
final class SseAggregator
{
    public static function toChatCompletion(string $sse, string $fallbackModel, string $fallbackId): string
    {
        $content = [];
        $reasoning = [];
        $toolCalls = [];
        $roles = [];
        $finish = null;
        $usage = null;
        $id = null;
        $model = null;
        $created = null;
        $sawChunk = false;

        foreach (preg_split('/\r?\n/', $sse) ?: [] as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $payload = trim(substr($line, 5));
            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }
            $chunk = json_decode($payload, true);
            if (!is_array($chunk)) {
                continue;
            }
            $sawChunk = true;
            if (is_string($chunk['id'] ?? null)) {
                $id ??= $chunk['id'];
            }
            if (is_string($chunk['model'] ?? null)) {
                $model ??= $chunk['model'];
            }
            if (is_int($chunk['created'] ?? null)) {
                $created ??= $chunk['created'];
            }
            if (is_array($chunk['usage'] ?? null)) {
                $usage = $chunk['usage'];
            }
            foreach ((array) ($chunk['choices'] ?? []) as $choice) {
                if (!is_array($choice)) {
                    continue;
                }
                $index = (int) ($choice['index'] ?? 0);
                $delta = $choice['delta'] ?? [];
                if (is_array($delta)) {
                    if (is_string($delta['role'] ?? null)) {
                        $roles[$index] = $delta['role'];
                    }
                    if (is_string($delta['content'] ?? null)) {
                        $content[$index] = ($content[$index] ?? '') . $delta['content'];
                    }
                    if (is_string($delta['reasoning_content'] ?? null)) {
                        $reasoning[$index] = ($reasoning[$index] ?? '') . $delta['reasoning_content'];
                    }
                    if (is_array($delta['tool_calls'] ?? null)) {
                        foreach ($delta['tool_calls'] as $call) {
                            if (!is_array($call)) {
                                continue;
                            }
                            $position = (int) ($call['index'] ?? count($toolCalls[$index] ?? []));
                            $slot = $toolCalls[$index][$position] ?? ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                            if (is_string($call['id'] ?? null) && $call['id'] !== '') {
                                $slot['id'] = $call['id'];
                            }
                            if (is_string($call['type'] ?? null) && $call['type'] !== '') {
                                $slot['type'] = $call['type'];
                            }
                            $fn = $call['function'] ?? [];
                            if (is_array($fn)) {
                                if (is_string($fn['name'] ?? null) && $fn['name'] !== '') {
                                    $slot['function']['name'] .= $fn['name'];
                                }
                                if (is_string($fn['arguments'] ?? null)) {
                                    $slot['function']['arguments'] .= $fn['arguments'];
                                }
                            }
                            $toolCalls[$index][$position] = $slot;
                        }
                    }
                }
                if (!empty($choice['finish_reason']) && is_string($choice['finish_reason'])) {
                    $finish = $choice['finish_reason'];
                }
            }
        }

        if (!$sawChunk) {
            // 上游返回的不是 SSE（例如错误体或普通 JSON），原样透传。
            return $sse;
        }

        $choices = [];
        foreach ($content + $reasoning + $toolCalls + $roles as $index => $_) {
            $message = ['role' => $roles[$index] ?? 'assistant'];
            $message['content'] = $content[$index] ?? '';
            if (isset($reasoning[$index])) {
                $message['reasoning_content'] = $reasoning[$index];
            }
            if (!empty($toolCalls[$index])) {
                $message['tool_calls'] = array_values($toolCalls[$index]);
            }
            $choices[] = [
                'index' => $index,
                'message' => $message,
                'finish_reason' => $finish ?? (empty($toolCalls[$index]) ? 'stop' : 'tool_calls'),
            ];
        }
        if ($choices === []) {
            $choices[] = [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => ''],
                'finish_reason' => $finish ?? 'stop',
            ];
        }

        $result = [
            'id' => $id ?? $fallbackId,
            'object' => 'chat.completion',
            'created' => $created ?? time(),
            'model' => $model ?? $fallbackModel,
            'choices' => $choices,
        ];
        if (is_array($usage)) {
            $result['usage'] = $usage;
        }
        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : $sse;
    }
}
