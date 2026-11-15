<?php

declare(strict_types=1);

namespace Relay\Repositories;

use Relay\Support\Database;

final class AnnouncementRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function active(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM announcements
             WHERE active = true
               AND (starts_at IS NULL OR starts_at <= now())
               AND (ends_at IS NULL OR ends_at >= now())
             ORDER BY id DESC LIMIT 5'
        );
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM announcements ORDER BY id DESC');
    }

    public function create(array $data): void
    {
        $this->db->execute(
            'INSERT INTO announcements (title, body, level, active, starts_at, ends_at)
             VALUES (?, ?, ?, ?, ?::timestamptz, ?::timestamptz)',
            [
                $data['title'],
                $data['body'] ?? '',
                $data['level'] ?? 'info',
                !empty($data['active']) ? 'true' : 'false',
                $data['starts_at'] ?? null,
                $data['ends_at'] ?? null,
            ]
        );
    }

    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE announcements SET title = ?, body = ?, level = ?, active = ?,
                    starts_at = ?::timestamptz, ends_at = ?::timestamptz WHERE id = ?',
            [
                $data['title'],
                $data['body'] ?? '',
                $data['level'] ?? 'info',
                !empty($data['active']) ? 'true' : 'false',
                $data['starts_at'] ?? null,
                $data['ends_at'] ?? null,
                $id,
            ]
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM announcements WHERE id = ?', [$id]);
    }
}
