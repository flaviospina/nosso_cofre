<?php
// app/Core/Database.php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Conexão PDO única por requisição.
 * - utf8mb4, exceções, prepared statements reais (EMULATE_PREPARES=false).
 * - Sessão MySQL em UTC: todo DATETIME é gravado em UTC e convertido para o fuso do usuário na exibição.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $transactionDepth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            Config::get('db.host'),
            Config::get('db.port', 3306),
            Config::get('db.name'),
            Config::get('db.charset', 'utf8mb4')
        );
        try {
            self::$pdo = new PDO($dsn, (string) Config::get('db.user'), (string) Config::get('db.pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
            ]);
        } catch (PDOException $e) {
            // Nunca expor host/usuário na mensagem; o detalhe vai para o log.
            Logger::critical('Falha ao conectar no banco de dados', ['error' => $e->getMessage()]);
            throw new RuntimeException('Não foi possível conectar ao banco de dados.', 0, $e);
        }
        return self::$pdo;
    }

    public static function isConfigured(): bool
    {
        return (string) Config::get('db.name') !== '' && (string) Config::get('db.user') !== '';
    }

    /** @param array<int|string,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @param array<int|string,mixed> $params
     *  @return array<int,array<string,mixed>> */
    public static function select(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params
     *  @return array<string,mixed>|null */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<int|string,mixed> $params */
    public static function execute(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    /** @param array<string,mixed> $data */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $columns)),
            implode(', ', $placeholders)
        );
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Executa o callback dentro de uma transação (com suporte a aninhamento por contagem).
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        if (self::$transactionDepth === 0) {
            $pdo->beginTransaction();
        }
        self::$transactionDepth++;
        try {
            $result = $callback();
            self::$transactionDepth--;
            if (self::$transactionDepth === 0) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            self::$transactionDepth--;
            if (self::$transactionDepth === 0 && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Usado pela tela /saude: testa a conexão sem lançar exceção para fora. */
    public static function ping(): bool
    {
        try {
            return self::scalar('SELECT 1') !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public static function serverVersion(): string
    {
        try {
            return (string) self::pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return '';
        }
    }
}
