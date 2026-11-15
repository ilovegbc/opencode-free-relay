<?php

declare(strict_types=1);

namespace Relay\Repositories;

use Relay\Support\Database;

final class ModelRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM model_catalog ORDER BY is_free DESC, sort_order, name');
    }

    /** @return list<array<string,mixed>> */
    public function publicList(?array $allowedModels = null): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM model_catalog WHERE is_free = true AND enabled = true AND available = true ORDER BY sort_order, name'
        );
        if ($allowedModels === null || $allowedModels === []) {
            return $rows;
        }
        $allowed = array_fill_keys(array_map('strval', $allowedModels), true);
        return array_values(array_filter($rows, static fn (array $row): bool => isset($allowed[(string) $row['id']])));
    }

    public function find(string $id): ?array
    {
        return $this->db->fetch('SELECT * FROM model_catalog WHERE id = ?', [$id]);
    }

    /**
     * Resolve an incoming model name. Accepts exact IDs, `opencode/<id>`,
     * aliases and case-insensitive IDs.
     */
    public function resolve(string $requested): ?array
    {
        $requested = trim($requested);
        if ($requested === '') {
            return null;
        }
        $candidates = [$requested];
        if (stripos($requested, 'opencode/') === 0) {
            $candidates[] = substr($requested, 9);
        }
        if (str_contains($requested, '/')) {
            $candidates[] = substr($requested, strrpos($requested, '/') + 1);
        }
        foreach (array_unique($candidates) as $candidate) {
            $row = $this->find($candidate);
            if ($row !== null) {
                return $row;
            }
            $row = $this->db->fetch(
                'SELECT m.* FROM model_aliases a JOIN model_catalog m ON m.id = a.model_id WHERE lower(a.alias) = lower(?)',
                [$candidate]
            );
            if ($row !== null) {
                return $row;
            }
            $row = $this->db->fetch('SELECT * FROM model_catalog WHERE lower(id) = lower(?) LIMIT 1', [$candidate]);
            if ($row !== null) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @return array{inserted:bool,changed:bool,id:string}
     */
    public function upsertFromRemote(array $model, bool $autoEnable): array
    {
        $id = (string) ($model['id'] ?? '');
        if ($id === '') {
            throw new \InvalidArgumentException('Model id is required.');
        }
        $isFree = self::isFree($model);
        $kind = self::upstreamKind($model);
        $baseUrl = self::upstreamBaseUrl($model, $kind);
        $supported = $kind !== null && $baseUrl !== '';
        $cost = $model['cost']['text'] ?? [];
        $inputPrice = (float) ($cost['input']['price'] ?? 0);
        $outputPrice = (float) ($cost['output']['price'] ?? 0);
        $limit = $model['limit'] ?? [];

        $row = $this->db->fetch(
            'INSERT INTO model_catalog
                (id, name, provider, description, family, type, is_free, available, enabled, upstream_kind,
                 upstream_base_url, context_limit, output_limit, input_price, output_price, capabilities,
                 modalities, api, raw, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?::jsonb, 0)
             ON CONFLICT (id) DO UPDATE SET
                name = EXCLUDED.name,
                provider = EXCLUDED.provider,
                description = EXCLUDED.description,
                family = EXCLUDED.family,
                type = EXCLUDED.type,
                is_free = EXCLUDED.is_free,
                available = EXCLUDED.available,
                upstream_kind = EXCLUDED.upstream_kind,
                upstream_base_url = EXCLUDED.upstream_base_url,
                context_limit = EXCLUDED.context_limit,
                output_limit = EXCLUDED.output_limit,
                input_price = EXCLUDED.input_price,
                output_price = EXCLUDED.output_price,
                capabilities = EXCLUDED.capabilities,
                modalities = EXCLUDED.modalities,
                api = EXCLUDED.api,
                raw = EXCLUDED.raw,
                last_seen_at = now()
             RETURNING id, (xmax = 0) AS inserted',
            [
                $id,
                (string) ($model['name'] ?? $id),
                (string) ($model['owned_by'] ?? 'opencode'),
                (string) ($model['description'] ?? ''),
                (string) ($model['family'] ?? ''),
                (string) ($model['type'] ?? 'chat'),
                $isFree ? 'true' : 'false',
                $supported ? 'true' : 'false',
                ($autoEnable && $isFree && $supported) ? 'true' : 'false',
                $kind ?? 'chat',
                $baseUrl,
                isset($limit['context']) ? (int) $limit['context'] : null,
                isset($limit['output']) ? (int) $limit['output'] : null,
                $inputPrice,
                $outputPrice,
                json_encode($model['capabilities'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($model['modalities'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($model['api'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($model, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );

        if ($row === null) {
            throw new \RuntimeException('Could not upsert model ' . $id);
        }
        return [
            'inserted' => (bool) ($row['inserted'] ?? false),
            'changed' => true,
            'id' => $id,
        ];
    }

    public static function isFree(array $model): bool
    {
        $type = (string) ($model['type'] ?? 'chat');
        if ($type !== 'chat') {
            return false;
        }
        $cost = $model['cost']['text'] ?? null;
        $freeByCost = is_array($cost)
            && array_key_exists('input', $cost)
            && (float) ($cost['input']['price'] ?? -1) === 0.0
            && (float) ($cost['output']['price'] ?? -1) === 0.0;
        $name = (string) ($model['name'] ?? '');
        $freeByName = preg_match('/\bfree\b/i', $name) === 1;
        // Big Pickle is a well-known zero-cost stealth model even though its
        // display name does not contain "Free". Cost metadata is authoritative.
        return $freeByCost || $freeByName;
    }

    public static function upstreamKind(array $model): ?string
    {
        $api = $model['api'] ?? [];
        if (!is_array($api)) {
            return null;
        }
        if (isset($api['chat-completions'])) {
            return 'chat';
        }
        if (isset($api['messages'])) {
            return 'messages';
        }
        if (isset($api['responses'])) {
            return 'responses';
        }
        return null;
    }

    public static function upstreamBaseUrl(array $model, ?string $kind): string
    {
        $api = $model['api'] ?? [];
        if (!is_array($api) || $kind === null) {
            return '';
        }
        $key = match ($kind) {
            'chat' => 'chat-completions',
            'messages' => 'messages',
            'responses' => 'responses',
            default => '',
        };
        $base = $api[$key]['base_url'] ?? '';
        return is_string($base) ? rtrim($base, '/') : '';
    }

    public function markUnavailableExcept(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->execute(
            'UPDATE model_catalog SET available = false WHERE is_free = true AND id NOT IN (' . $placeholders . ')',
            array_values($ids)
        );
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $this->db->execute('UPDATE model_catalog SET enabled = ? WHERE id = ?', [$enabled ? 'true' : 'false', $id]);
    }

    public function updateConfig(string $id, array $data): void
    {
        $allowed = ['enabled', 'credit_per_request', 'credit_per_1k_input', 'credit_per_1k_output', 'credit_multiplier', 'sort_order'];
        $sets = [];
        $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = $field . ' = ?';
                $params[] = is_bool($data[$field]) ? ($data[$field] ? 'true' : 'false') : $data[$field];
            }
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->db->execute('UPDATE model_catalog SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    /** @return list<array<string,mixed>> */
    public function aliases(): array
    {
        return $this->db->fetchAll('SELECT a.*, m.name AS model_name FROM model_aliases a JOIN model_catalog m ON m.id = a.model_id ORDER BY a.alias');
    }

    public function addAlias(string $alias, string $modelId): void
    {
        $this->db->execute(
            'INSERT INTO model_aliases (alias, model_id) VALUES (?, ?) ON CONFLICT (alias) DO UPDATE SET model_id = EXCLUDED.model_id',
            [$alias, $modelId]
        );
    }

    public function removeAlias(string $alias): void
    {
        $this->db->execute('DELETE FROM model_aliases WHERE alias = ?', [$alias]);
    }

    public function lastSync(): ?array
    {
        return $this->db->fetch('SELECT * FROM model_sync_runs ORDER BY id DESC LIMIT 1');
    }

    /** @return list<array<string,mixed>> */
    public function syncRuns(int $limit = 20): array
    {
        return $this->db->fetchAll('SELECT * FROM model_sync_runs ORDER BY id DESC LIMIT ?', [$limit]);
    }

    public function countFree(): int
    {
        return (int) $this->db->scalar('SELECT count(*) FROM model_catalog WHERE is_free = true AND enabled = true AND available = true');
    }
}
