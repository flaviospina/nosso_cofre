<?php
// app/Core/Env.php
declare(strict_types=1);

namespace App\Core;

/**
 * Leitor de arquivo .env sem dependências.
 * Suporta comentários (#), valores entre aspas simples/duplas e o prefixo "export ".
 * Os valores ficam em memória estática (não vão para $_ENV nem getenv, para não vazar em phpinfo/logs).
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $file): void
    {
        self::$loaded = true;
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key === '' || !preg_match('/^[A-Z0-9_]+$/i', $key)) {
                continue;
            }
            $value = self::unquote($value);
            self::$values[$key] = $value;
        }
    }

    private static function unquote(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $first = $value[0];
        if (($first === '"' || $first === "'") && strlen($value) >= 2) {
            $end = strrpos($value, $first);
            if ($end !== false && $end > 0) {
                $inner = substr($value, 1, $end - 1);
                return $first === '"' ? stripcslashes($inner) : $inner;
            }
        }
        // Remove comentário no fim da linha (valor sem aspas)
        $hash = strpos($value, ' #');
        if ($hash !== false) {
            $value = rtrim(substr($value, 0, $hash));
        }
        return $value;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::$values[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        if (!isset(self::$values[$key]) || self::$values[$key] === '') {
            return $default;
        }
        return in_array(strtolower(self::$values[$key]), ['1', 'true', 'yes', 'on', 'sim'], true);
    }

    public static function has(string $key): bool
    {
        return isset(self::$values[$key]) && self::$values[$key] !== '';
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}
