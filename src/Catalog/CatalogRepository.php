<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Support\Database;

final class CatalogRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<CatalogItem>
     */
    public function packages(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM packages' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_active DESC, price';

        return array_map(CatalogItem::packageFromRow(...), $this->db->fetchAll($sql));
    }

    /**
     * @return list<CatalogItem>
     */
    public function addons(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM addons' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_active DESC, name';

        return array_map(CatalogItem::addonFromRow(...), $this->db->fetchAll($sql));
    }

    public function find(string $type, int $id): ?CatalogItem
    {
        if ($type === 'package') {
            $row = $this->db->fetchOne('SELECT * FROM packages WHERE id = :id', ['id' => $id]);

            return $row === null ? null : CatalogItem::packageFromRow($row);
        }
        $row = $this->db->fetchOne('SELECT * FROM addons WHERE id = :id', ['id' => $id]);

        return $row === null ? null : CatalogItem::addonFromRow($row);
    }

    public function nameTaken(string $type, string $name, int $exceptId = 0): bool
    {
        $table = $type === 'package' ? 'packages' : 'addons';

        return $this->db->fetchValue(
            "SELECT COUNT(*) FROM $table WHERE name = :name AND id <> :id",
            ['name' => $name, 'id' => $exceptId]
        ) > 0;
    }

    public function createPackage(string $name, string $description, int $price): int
    {
        return $this->db->insert(
            'INSERT INTO packages (name, description, price) VALUES (:name, :description, :price)',
            ['name' => $name, 'description' => $description, 'price' => $price]
        );
    }

    public function updatePackage(int $id, string $name, string $description, int $price, bool $active): void
    {
        $this->db->run(
            'UPDATE packages SET name = :name, description = :description, price = :price, is_active = :active
             WHERE id = :id',
            ['id' => $id, 'name' => $name, 'description' => $description, 'price' => $price, 'active' => (int) $active]
        );
    }

    public function createAddon(string $name, string $unitLabel, int $price): int
    {
        return $this->db->insert(
            'INSERT INTO addons (name, unit_label, price) VALUES (:name, :unit_label, :price)',
            ['name' => $name, 'unit_label' => $unitLabel, 'price' => $price]
        );
    }

    public function updateAddon(int $id, string $name, string $unitLabel, int $price, bool $active): void
    {
        $this->db->run(
            'UPDATE addons SET name = :name, unit_label = :unit_label, price = :price, is_active = :active
             WHERE id = :id',
            ['id' => $id, 'name' => $name, 'unit_label' => $unitLabel, 'price' => $price, 'active' => (int) $active]
        );
    }
}
