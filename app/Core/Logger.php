<?php
// app/Core/Logger.php
declare(strict_types=1);

namespace App\Core;

/**
 * Log em arquivo diário (storage/logs/app-AAAA-MM-DD.log) com rotação por retenção em dias.
 * Formato de linha: [data ISO] NÍVEL: mensagem {contexto em JSON}
 * Nunca registrar senhas, tokens ou conteúdo de campos criptografados no contexto.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];
    private static bool $rotated = false;

    /** @param array<string,mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function critical(string $message, array $context = []): void
    {
        self::log('critical', $message, $context);
    }

    /** Canal separado para eventos de segurança (login, bloqueio, CSRF, etc.). */
    /** @param array<string,mixed> $context */
    public static function security(string $message, array $context = []): void
    {
        self::log('warning', $message, $context, 'security');
    }

    /** @param array<string,mixed> $context */
    public static function log(string $level, string $message, array $context = [], string $channel = 'app'): void
    {
        $minLevel = self::LEVELS[strtolower((string) Config::get('log.level', 'info'))] ?? 1;
        $levelValue = self::LEVELS[$level] ?? 1;
        if ($levelValue < $minLevel) {
            return;
        }
        $dir = (string) Config::get('paths.logs', APP_ROOT . '/storage/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $file = sprintf('%s/%s-%s.log', $dir, $channel, gmdate('Y-m-d'));
        $line = sprintf(
            "[%s] %s: %s%s\n",
            gmdate('c'),
            strtoupper($level),
            $message,
            $context !== [] ? ' ' . json_encode(self::sanitize($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : ''
        );
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        self::rotate($dir);
    }

    /** Remove das entradas chaves que jamais devem ir para o log. */
    /** @param array<string,mixed> $context
     *  @return array<string,mixed> */
    private static function sanitize(array $context): array
    {
        $blocked = ['password', 'password_confirmation', 'senha', 'token', 'secret', 'totp', 'authorization', 'cookie', 'app_key', 'db_pass', 'mail_pass'];
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $blocked, true)) {
                $context[$key] = '[oculto]';
            } elseif (is_array($value)) {
                $context[$key] = self::sanitize($value);
            } elseif ($value instanceof \Throwable) {
                $context[$key] = get_class($value) . ': ' . $value->getMessage() . ' em ' . $value->getFile() . ':' . $value->getLine();
            }
        }
        return $context;
    }

    /** Apaga arquivos de log mais antigos que a retenção configurada (uma vez por requisição, no máximo). */
    private static function rotate(string $dir): void
    {
        if (self::$rotated) {
            return;
        }
        self::$rotated = true;
        // Só verifica em ~2% das requisições para não custar I/O em todas
        if (random_int(1, 50) !== 1) {
            return;
        }
        $days = max(1, (int) Config::get('log.retention_days', 30));
        $limit = time() - ($days * 86400);
        foreach (glob($dir . '/*.log') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $limit) {
                @unlink($file);
            }
        }
    }
}
