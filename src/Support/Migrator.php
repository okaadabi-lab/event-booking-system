<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Applies numbered .sql files in order and records each one in schema_migrations.
 * MySQL commits DDL implicitly, so a failed file is not rolled back: fix it and rerun.
 */
final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $directory)
    {
    }

    /**
     * @return list<string> versions applied by this run
     */
    public function migrate(): array
    {
        $this->db->run(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $applied = array_map('strval', $this->db->run('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN));

        $ran = [];
        foreach ($this->files() as $version => $path) {
            if (in_array($version, $applied, true)) {
                continue;
            }
            foreach (self::statements((string) file_get_contents($path)) as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->run('INSERT INTO schema_migrations (version) VALUES (:version)', ['version' => $version]);
            $ran[] = $version;
        }

        return $ran;
    }

    public function dropAllTables(): void
    {
        $tables = $this->db->run('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(\PDO::FETCH_COLUMN);
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->db->pdo()->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
        }
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @return array<string, string> version => absolute path, sorted by version
     */
    public function files(): array
    {
        $files = [];
        foreach (glob($this->directory . '/*.sql') ?: [] as $path) {
            $files[basename($path, '.sql')] = $path;
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Splits a migration file on semicolons that end a line. Migrations do not
     * contain stored procedures, so this is sufficient.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $withoutComments = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        $parts = preg_split('/;\s*$/m', $withoutComments) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
