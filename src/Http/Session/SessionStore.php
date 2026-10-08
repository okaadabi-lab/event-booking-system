<?php

declare(strict_types=1);

namespace App\Http\Session;

use App\Support\Clock;
use App\Support\Database;
use App\Support\Row;

/**
 * Sessions live in MySQL rather than PHP's file handler so they survive across
 * containers and can be revoked per user (e.g. when an account is deactivated).
 */
final class SessionStore
{
    public const IDLE_TIMEOUT_SECONDS = 2 * 3600;

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public function load(string $id): ?Session
    {
        if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT payload FROM sessions WHERE id_hash = :hash AND last_activity_at > :cutoff',
            ['hash' => self::hash($id), 'cutoff' => $this->cutoff()]
        );
        if ($row === null) {
            return null;
        }
        $data = json_decode(Row::string($row, 'payload'), true);

        return new Session($id, is_array($data) ? $data : [], false);
    }

    public function save(Session $session): void
    {
        if ($session->previousId() !== null) {
            $this->delete($session->previousId());
        }
        if ($session->isDestroyed()) {
            return;
        }
        $userId = $session->userId();
        $this->db->run(
            'INSERT INTO sessions (id_hash, user_id, payload, last_activity_at)
             VALUES (:hash, :user_id, :payload, :now)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), payload = VALUES(payload),
                last_activity_at = VALUES(last_activity_at)',
            [
                'hash' => self::hash($session->id()),
                'user_id' => $userId,
                'payload' => json_encode($session->all(), JSON_THROW_ON_ERROR),
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Refreshes last activity without rewriting the payload, at most once a minute.
     */
    public function touch(Session $session): void
    {
        $this->db->run(
            'UPDATE sessions SET last_activity_at = :now
             WHERE id_hash = :hash AND last_activity_at < :threshold',
            [
                'hash' => self::hash($session->id()),
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'threshold' => $this->clock->now()->modify('-60 seconds')->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function delete(string $id): void
    {
        $this->db->run('DELETE FROM sessions WHERE id_hash = :hash', ['hash' => self::hash($id)]);
    }

    public function pruneExpired(): void
    {
        $this->db->run('DELETE FROM sessions WHERE last_activity_at <= :cutoff', ['cutoff' => $this->cutoff()]);
    }

    private function cutoff(): string
    {
        return $this->clock->now()->modify('-' . self::IDLE_TIMEOUT_SECONDS . ' seconds')->format('Y-m-d H:i:s');
    }

    private static function hash(string $id): string
    {
        return hash('sha256', $id);
    }
}
