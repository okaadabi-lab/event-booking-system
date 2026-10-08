<?php

declare(strict_types=1);

namespace App\Activity;

use App\Support\Clock;
use App\Support\Database;
use App\Support\Row;

/**
 * Append-only audit trail. Callers write inside their own transaction so the
 * log entry and the change it describes commit or roll back together.
 */
final class ActivityLog
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        string $entityType,
        int $entityId,
        string $action,
        string $summary,
        ?int $actorUserId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->db->run(
            'INSERT INTO activity_log
                (entity_type, entity_id, action, summary, before_state, after_state, actor_user_id, created_at)
             VALUES (:type, :id, :action, :summary, :before, :after, :actor, :created_at)',
            [
                'type' => $entityType,
                'id' => $entityId,
                'action' => $action,
                'summary' => mb_substr($summary, 0, 255),
                'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'actor' => $actorUserId,
                'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * @return list<ActivityEntry>
     */
    public function forEntity(string $entityType, int $entityId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT a.*, u.name AS actor_name
             FROM activity_log a
             LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.entity_type = :type AND a.entity_id = :id
             ORDER BY a.id DESC',
            ['type' => $entityType, 'id' => $entityId]
        );

        return array_map(static fn (array $row): ActivityEntry => new ActivityEntry(
            Row::string($row, 'action'),
            Row::string($row, 'summary'),
            Row::nullableString($row, 'actor_name'),
            Row::date($row, 'created_at'),
            self::decode(Row::nullableString($row, 'before_state')),
            self::decode(Row::nullableString($row, 'after_state')),
        ), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : null;
    }
}
