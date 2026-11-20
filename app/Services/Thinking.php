<?php

declare(strict_types=1);

namespace Relay\Services;

/**
 * 思考等级（reasoning effort）的提取、校验与请求改写。
 *
 * 上游模型目录通过 `capabilities.reasoning` 声明思考能力：
 *   - supported: 是否具备思考能力
 *   - effort:    可选档位（low/medium/high/xhigh/max…）
 *   - toggle:    true 表示可以关闭思考（对应 `none` 档）
 *   - default:   {type:"effort", value:"high"} 形式的默认档
 *   - budget:    {min,max} 形式的思考 token 预算
 *
 * OpenCode 客户端根据这些字段生成 `#variant`，选中后把档位作为
 * `reasoning_effort` 写进请求体；其他客户端可能用 `reasoning.effort`、
 * `thinking.effort` 或 `thinking.budget_tokens`。本类把它们统一收敛成
 * 上游 chat-completions 真正识别的 `reasoning_effort`。
 *
 * 实测（2026-11）：上游对模型未声明的档位不会报错，而是静默丢弃思考
 * （deepseek-v4.1-flash-free 的 effort 只有 low/high/max，发 medium 后
 * reasoning_tokens=0）。因此这里在转发前把未知档位收敛到最近的合法档，
 * 避免“选了档位却没有思考”。
 */
final class Thinking
{
    /** 由低到高的思考档位阶梯；`none` 表示关闭思考。 */
    public const LADDER = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * 从上游模型对象提取思考配置。
     *
     * @param array<string,mixed> $remote
     * @return array{supported:bool,toggle:bool,efforts:list<string>,levels:list<string>,default:?string,budget:?array<string,int>}
     */
    public static function normalize(array $remote): array
    {
        $capabilities = $remote['capabilities'] ?? [];
        $reasoning = is_array($capabilities) ? ($capabilities['reasoning'] ?? []) : [];

        return self::fromReasoning(is_array($reasoning) ? $reasoning : []);
    }

    /**
     * @param array<string,mixed> $reasoning
     * @return array{supported:bool,toggle:bool,efforts:list<string>,levels:list<string>,default:?string,budget:?array<string,int>}
     */
    public static function fromReasoning(array $reasoning): array
    {
        $supported = (bool) ($reasoning['supported'] ?? false);
        $toggle = (bool) ($reasoning['toggle'] ?? false);

        $efforts = [];
        $rawEfforts = $reasoning['effort'] ?? [];
        if (is_array($rawEfforts)) {
            foreach ($rawEfforts as $level) {
                if (!is_string($level)) {
                    continue;
                }
                $level = strtolower(trim($level));
                if ($level !== '' && $level !== 'default' && !in_array($level, $efforts, true)) {
                    $efforts[] = $level;
                }
            }
        }

        $budget = null;
        $rawBudget = $reasoning['budget'] ?? null;
        if (is_array($rawBudget)) {
            $min = isset($rawBudget['min']) ? (int) $rawBudget['min'] : null;
            $max = isset($rawBudget['max']) ? (int) $rawBudget['max'] : null;
            if ($min !== null || $max !== null) {
                $budget = array_filter(['min' => $min, 'max' => $max], static fn ($v) => $v !== null);
            }
        }

        $levels = $efforts;
        if ($toggle && !in_array('none', $levels, true)) {
            $levels[] = 'none';
        }
        $levels = self::order($levels);

        $default = null;
        $rawDefault = $reasoning['default'] ?? null;
        if (is_string($rawDefault)) {
            $default = strtolower(trim($rawDefault));
        } elseif (is_array($rawDefault) && is_string($rawDefault['value'] ?? null)) {
            $default = strtolower(trim((string) $rawDefault['value']));
        }
        if ($default === 'default' || $default === '') {
            $default = null;
        }

        if (!$supported) {
            $efforts = [];
            $levels = [];
            $default = null;
            $budget = null;
        }

        return [
            'supported' => $supported,
            'toggle' => $toggle,
            'efforts' => $efforts,
            'levels' => $levels,
            'default' => $default,
            'budget' => $budget,
        ];
    }

    /**
     * 读取 model_catalog 行里的思考配置；旧行没有 thinking 列时回退到 capabilities。
     *
     * @param array<string,mixed> $model
     * @return array{supported:bool,toggle:bool,efforts:list<string>,levels:list<string>,default:?string,budget:?array<string,int>}
     */
    public static function forModel(array $model): array
    {
        $stored = $model['thinking'] ?? null;
        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            $stored = is_array($decoded) ? $decoded : null;
        }
        if (is_array($stored) && array_key_exists('supported', $stored)) {
            return self::fromNormalized($stored);
        }

        $capabilities = $model['capabilities'] ?? [];
        $reasoning = is_array($capabilities) ? ($capabilities['reasoning'] ?? []) : [];
        return self::fromReasoning(is_array($reasoning) ? $reasoning : []);
    }

    /**
     * @param array<string,mixed> $stored
     * @return array{supported:bool,toggle:bool,efforts:list<string>,levels:list<string>,default:?string,budget:?array<string,int>}
     */
    private static function fromNormalized(array $stored): array
    {
        $reasoning = [
            'supported' => (bool) ($stored['supported'] ?? false),
            'toggle' => (bool) ($stored['toggle'] ?? false),
            'effort' => is_array($stored['efforts'] ?? null) ? $stored['efforts'] : [],
            'default' => $stored['default'] ?? null,
            'budget' => $stored['budget'] ?? null,
        ];
        return self::fromReasoning($reasoning);
    }

    /**
     * 把 `id#high` / `id:high` 拆成模型 ID 与档位；只有后缀是合法档位时才拆。
     *
     * @return array{0:string,1:?string}
     */
    public static function splitModelId(string $requested): array
    {
        $requested = trim($requested);
        foreach (['#', ':'] as $separator) {
            $position = strrpos($requested, $separator);
            if ($position === false || $position === 0) {
                continue;
            }
            $tail = strtolower(trim(substr($requested, $position + 1)));
            if (in_array($tail, self::LADDER, true)) {
                return [substr($requested, 0, $position), $tail];
            }
        }
        return [$requested, null];
    }

    /**
     * 从请求体读取调用方选择的档位（模型 ID 后缀由调用方单独传入）。
     *
     * @param array<string,mixed> $body
     */
    public static function requestedLevel(array $body): ?string
    {
        $direct = $body['reasoning_effort'] ?? null;
        if (is_string($direct) && trim($direct) !== '') {
            return strtolower(trim($direct));
        }

        $reasoning = $body['reasoning'] ?? null;
        if (is_array($reasoning) && is_string($reasoning['effort'] ?? null) && trim((string) $reasoning['effort']) !== '') {
            return strtolower(trim((string) $reasoning['effort']));
        }

        $thinking = $body['thinking'] ?? null;
        if (is_array($thinking)) {
            if (strtolower((string) ($thinking['type'] ?? '')) === 'disabled') {
                return 'none';
            }
            if (is_string($thinking['effort'] ?? null) && trim((string) $thinking['effort']) !== '') {
                return strtolower(trim((string) $thinking['effort']));
            }
            if (isset($thinking['budget_tokens']) && is_numeric($thinking['budget_tokens'])) {
                return self::levelForBudget((int) $thinking['budget_tokens']);
            }
        }

        return null;
    }

    /**
     * 把调用方档位收敛到模型真实支持的档位。
     *  - 精确命中：原样返回
     *  - 模型没有 effort 列表：只接受阶梯内的已知档位并原样透传
     *  - 其他值：取阶梯上最近的支持档位（等距时取更高档），`auto`/`default` 视为未指定
     *
     * @param array{supported:bool,toggle:bool,efforts:list<string>,levels:list<string>,default:?string,budget:?array<string,int>} $thinking
     */
    public static function resolveLevel(array $thinking, ?string $requested): ?string
    {
        if ($requested === null) {
            return null;
        }
        $level = strtolower(trim($requested));
        if ($level === '' || $level === 'auto' || $level === 'default') {
            return null;
        }
        if (in_array($level, $thinking['levels'], true)) {
            return $level;
        }

        $efforts = $thinking['efforts'];
        if ($efforts === []) {
            return in_array($level, self::LADDER, true) ? $level : null;
        }

        $target = self::ladderIndex($level);
        if ($target === null) {
            return null;
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;
        $bestIndex = -1;
        foreach ($efforts as $candidate) {
            $index = self::ladderIndex($candidate);
            if ($index === null) {
                continue;
            }
            $distance = abs($index - $target);
            if ($distance < $bestDistance || ($distance === $bestDistance && $index > $bestIndex)) {
                $best = $candidate;
                $bestDistance = $distance;
                $bestIndex = $index;
            }
        }
        return $best;
    }

    /**
     * 模型的最高思考档位；没有声明 effort 列表时返回 null（交给上游默认）。
     *
     * @param array{supported:bool,toggle:bool,efforts:list<string>,levels:list<string>,default:?string,budget:?array<string,int>} $thinking
     */
    public static function highestLevel(array $thinking): ?string
    {
        $best = null;
        $bestIndex = -1;
        foreach ($thinking['efforts'] as $level) {
            $index = self::ladderIndex($level);
            if ($index === null) {
                $best ??= $level;
                continue;
            }
            if ($index > $bestIndex) {
                $best = $level;
                $bestIndex = $index;
            }
        }
        return $best;
    }

    /**
     * 按上游端点类型写入档位字段。
     *
     * @param array<string,mixed> $body
     * @param array{supported:bool,toggle:bool,efforts:list<string>,levels:list<string>,default:?string,budget:?array<string,int>} $thinking
     * @return array<string,mixed>
     */
    public static function apply(array $body, array $thinking, string $kind, ?string $level): array
    {
        if ($level === null) {
            return self::dropDefaultTokens($body, $kind);
        }
        $level = strtolower(trim($level));
        if ($level === '') {
            return self::dropDefaultTokens($body, $kind);
        }

        if ($kind === 'chat') {
            // 上游 chat-completions 只认 reasoning_effort；reasoning/thinking 会被忽略。
            $body['reasoning_effort'] = $level;
            return $body;
        }

        if ($kind === 'responses') {
            $reasoning = $body['reasoning'] ?? [];
            if (!is_array($reasoning)) {
                $reasoning = [];
            }
            $reasoning['effort'] = $level;
            $body['reasoning'] = $reasoning;
            unset($body['reasoning_effort']);
            return $body;
        }

        // Anthropic Messages：调用方通常已经按官方格式发送 thinking，不强行改写。
        return $body;
    }

    /**
     * `auto` / `default` 不是上游真实档位，留在请求里会让上游静默不思考；
     * 既然语义是"跟随上游默认"，直接摘掉该字段。
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private static function dropDefaultTokens(array $body, string $kind): array
    {
        if ($kind === 'chat') {
            $value = $body['reasoning_effort'] ?? null;
            if (is_string($value) && in_array(strtolower(trim($value)), ['auto', 'default'], true)) {
                unset($body['reasoning_effort']);
            }
            return $body;
        }
        if ($kind === 'responses') {
            $reasoning = $body['reasoning'] ?? null;
            if (is_array($reasoning) && is_string($reasoning['effort'] ?? null)
                && in_array(strtolower(trim((string) $reasoning['effort'])), ['auto', 'default'], true)) {
                unset($reasoning['effort']);
                $body['reasoning'] = $reasoning;
            }
            return $body;
        }
        return $body;
    }

    private static function levelForBudget(int $budget): string
    {
        return match (true) {
            $budget <= 0 => 'none',
            $budget < 2048 => 'low',
            $budget < 8192 => 'medium',
            $budget < 32768 => 'high',
            default => 'xhigh',
        };
    }

    /** @param list<string> $levels @return list<string> */
    private static function order(array $levels): array
    {
        $known = [];
        $unknown = [];
        foreach ($levels as $level) {
            $index = self::ladderIndex($level);
            if ($index === null) {
                $unknown[] = $level;
            } else {
                $known[$index] = $level;
            }
        }
        ksort($known);
        return array_values(array_merge(array_values($known), $unknown));
    }

    private static function ladderIndex(string $level): ?int
    {
        $index = array_search($level, self::LADDER, true);
        return $index === false ? null : (int) $index;
    }
}
