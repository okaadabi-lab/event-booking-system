<?php

declare(strict_types=1);

namespace App\Resource;

use App\Support\Database;

final class ResourceRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<Resource>
     */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM resources' . ($activeOnly ? ' WHERE is_active = 1' : '')
            . " ORDER BY is_active DESC, FIELD(type, 'venue', 'crew'), name";

        return array_map(Resource::fromRow(...), $this->db->fetchAll($sql));
    }

    public function find(int $id): ?Resource
    {
        $row = $this->db->fetchOne('SELECT * FROM resources WHERE id = :id', ['id' => $id]);

        return $row === null ? null : Resource::fromRow($row);
    }

    /**
     * Takes an exclusive row lock on the resource until the surrounding transaction
     * ends. Every write that could create an overlap for this resource takes this
     * lock first, so such writes run one at a time per resource.
     */
    public function lockForUpdate(int $id): ?Resource
    {
        $row = $this->db->fetchOne('SELECT * FROM resources WHERE id = :id FOR UPDATE', ['id' => $id]);

        return $row === null ? null : Resource::fromRow($row);
    }

    public function nameTaken(string $name, int $exceptId = 0): bool
    {
        return $this->db->fetchValue(
            'SELECT COUNT(*) FROM resources WHERE name = :name AND id <> :id',
            ['name' => $name, 'id' => $exceptId]
        ) > 0;
    }

    public function create(string $name, ResourceType $type, ?int $capacity): int
    {
        return $this->db->insert(
            'INSERT INTO resources (name, type, capacity) VALUES (:name, :type, :capacity)',
            ['name' => $name, 'type' => $type->value, 'capacity' => $capacity]
        );
    }

    public function update(int $id, string $name, ?int $capacity, bool $active): void
    {
        $this->db->run(
            'UPDATE resources SET name = :name, capacity = :capacity, is_active = :active WHERE id = :id',
            ['id' => $id, 'name' => $name, 'capacity' => $capacity, 'active' => (int) $active]
        );
    }
}
