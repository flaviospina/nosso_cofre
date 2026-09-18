<?php
// app/Services/BackupService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use RuntimeException;

/**
 * Backup do banco em PHP puro (sem mysqldump): SQL completo (estrutura + dados) → gzip → AES-256-GCM com BACKUP_KEY,
 * gravado em storage/backups/nosso-cofre-AAAA-MM-DD-HHMM.sql.gz.enc. O cron faz um por dia e apaga os mais antigos que a retenção.
 * Restauração: tools/restaurar-backup.php (linha de comando) ou o passo a passo do LEIA-ME com phpMyAdmin.
 */
final class BackupService
{
    /** Gera o dump SQL (estrutura + dados) de todas as tabelas do banco. */
    public static function dump(): string
    {
        $out = "-- Nosso Cofre — backup de " . gmdate('Y-m-d H:i:s') . " UTC\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET time_zone = '+00:00';\n\n";
        $tables = array_map(static fn(array $r): string => (string) array_values($r)[0], Database::select('SHOW TABLES'));
        foreach ($tables as $table) {
            $create = Database::selectOne("SHOW CREATE TABLE `{$table}`");
            $ddl = (string) ($create['Create Table'] ?? '');
            $out .= "DROP TABLE IF EXISTS `{$table}`;\n{$ddl};\n\n";
            $offset = 0;
            while (true) {
                $rows = Database::select("SELECT * FROM `{$table}` LIMIT 500 OFFSET {$offset}");
                if ($rows === []) {
                    break;
                }
                $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $values = [];
                foreach ($rows as $row) {
                    $values[] = '(' . implode(', ', array_map(static fn($v): string => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : Database::pdo()->quote((string) $v)), array_values($row))) . ')';
                }
                $out .= "INSERT INTO `{$table}` ({$columns}) VALUES\n" . implode(",\n", $values) . ";\n";
                $offset += 500;
                if (count($rows) < 500) {
                    break;
                }
            }
            $out .= "\n";
        }
        return $out . "SET FOREIGN_KEY_CHECKS = 1;\n";
    }

    /** Cria o arquivo de backup criptografado e devolve o caminho. */
    public static function create(): string
    {
        $key = (string) Config::get('backup.key', '');
        if (strlen($key) < 32) {
            throw new RuntimeException('BACKUP_KEY não configurada no .env (gere em /instalar ou use uma sequência aleatória de 64 caracteres).');
        }
        $dir = (string) Config::get('paths.backups');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $sql = self::dump();
        $gz = gzencode($sql, 9);
        if ($gz === false) {
            throw new RuntimeException('Falha ao compactar o backup.');
        }
        $path = $dir . '/nosso-cofre-' . gmdate('Y-m-d-Hi') . '.sql.gz.enc';
        file_put_contents($path, Crypto::encrypt($gz, $key));
        chmod($path, 0640);
        Database::execute('INSERT INTO cron_runs (task, started_at, finished_at, status, message) VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)', ['backup', 'ok', basename($path) . ' (' . round(filesize($path) / 1024) . ' KB)']);
        Logger::info('Backup gerado', ['file' => basename($path), 'bytes' => filesize($path)]);
        return $path;
    }

    /** Descriptografa e descompacta um arquivo de backup, devolvendo o SQL. */
    public static function open(string $path, ?string $key = null): string
    {
        $key = $key ?? (string) Config::get('backup.key', '');
        $gz = Crypto::decrypt((string) file_get_contents($path), $key);
        $sql = gzdecode($gz);
        if ($sql === false) {
            throw new RuntimeException('Backup corrompido ou chave errada.');
        }
        return $sql;
    }

    /** Restaura um SQL no banco atual (usado pela ferramenta de linha de comando e pelos testes). */
    public static function restore(string $sql): int
    {
        $pdo = Database::pdo();
        $count = 0;
        foreach (self::splitStatements($sql) as $statement) {
            $pdo->exec($statement);
            $count++;
        }
        return $count;
    }

    /** Lista os backups existentes. @return list<array{name:string,path:string,size:int,created_at:string}> */
    public static function list(): array
    {
        $dir = (string) Config::get('paths.backups');
        $files = glob($dir . '/nosso-cofre-*.sql.gz.enc') ?: [];
        rsort($files);
        return array_map(static fn(string $f): array => ['name' => basename($f), 'path' => $f, 'size' => (int) filesize($f), 'created_at' => gmdate('Y-m-d H:i:s', (int) filemtime($f))], $files);
    }

    /** Apaga backups mais antigos que a retenção. */
    public static function purge(?int $days = null): int
    {
        $days = $days ?? max(1, (int) Config::get('backup.retention_days', 30));
        $n = 0;
        foreach (self::list() as $b) {
            if (filemtime($b['path']) < time() - $days * 86400) {
                unlink($b['path']);
                $n++;
            }
        }
        return $n;
    }

    /** Já existe backup nas últimas 20 h? */
    public static function madeToday(): bool
    {
        foreach (self::list() as $b) {
            if (filemtime($b['path']) > time() - 20 * 3600) {
                return true;
            }
        }
        return false;
    }

    /** Divide o SQL em comandos (respeita aspas e ponto e vírgula dentro de strings). @return list<string> */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($inString) {
                $current .= $ch;
                if ($ch === '\\') {
                    $current .= $sql[++$i] ?? '';
                } elseif ($ch === "'") {
                    $inString = false;
                }
                continue;
            }
            if ($ch === "'") {
                $inString = true;
                $current .= $ch;
            } elseif ($ch === '-' && ($sql[$i + 1] ?? '') === '-' && $current === '') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
            } elseif ($ch === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }
}
