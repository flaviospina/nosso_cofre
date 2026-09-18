<?php
// app/Core/Migrator.php
declare(strict_types=1);

namespace App\Core;

/**
 * Aplica as migrações de sql/migrations/NNN_nome.sql acima da versão gravada em settings (schema.version).
 * Instalações novas importam sql/schema.sql (já na versão atual); instalações existentes usam
 * a tela /sistema/migrar (protegida pelo CRON_TOKEN) ou o phpMyAdmin.
 */
final class Migrator
{
    public static function currentVersion(): int
    {
        try {
            return (int) Database::scalar("SELECT `value` FROM settings WHERE `key` = 'schema.version'");
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function targetVersion(): int
    {
        return (int) Config::get('app.schema_version', 1);
    }

    /** @return array<int,array{version:int,name:string,file:string}> */
    public static function pending(): array
    {
        $current = self::currentVersion();
        $dir = (string) Config::get('paths.root') . '/sql/migrations';
        $pending = [];
        foreach (glob($dir . '/*.sql') ?: [] as $file) {
            if (!preg_match('/^(\d{3})_(.+)\.sql$/', basename($file), $m)) {
                continue;
            }
            $version = (int) $m[1];
            if ($version > $current) {
                $pending[$version] = ['version' => $version, 'name' => $m[2], 'file' => $file];
            }
        }
        ksort($pending);
        return array_values($pending);
    }

    /** Executa as pendentes em ordem. Devolve a lista aplicada. */
    /** @return list<string> */
    public static function migrate(): array
    {
        $applied = [];
        foreach (self::pending() as $migration) {
            $sql = (string) file_get_contents($migration['file']);
            foreach (self::splitStatements($sql) as $statement) {
                Database::pdo()->exec($statement);
            }
            Database::execute(
                "INSERT INTO settings (`key`, `value`, description) VALUES ('schema.version', ?, 'Versão do esquema aplicada') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [(string) $migration['version']]
            );
            $applied[] = $migration['version'] . '_' . $migration['name'];
            Logger::info('Migração aplicada', ['migration' => $applied[count($applied) - 1]]);
        }
        return $applied;
    }

    /** Divide o arquivo em comandos por ";" no fim de linha (sem procedures/delimiters). */
    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = [];
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $statements[] = $chunk;
            }
        }
        return $statements;
    }
}
