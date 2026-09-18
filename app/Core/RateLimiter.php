<?php
// app/Core/RateLimiter.php
declare(strict_types=1);

namespace App\Core;

/**
 * Limite de tentativas com bloqueio progressivo, baseado na tabela login_attempts.
 * Conta falhas recentes por IP e por conta (e-mail); o tempo de bloqueio cresce com a quantidade de falhas.
 *
 *   falhas nos últimos 15 min:  3 → exige CAPTCHA
 *                                5 → bloqueio de 1 min
 *                                8 → 5 min
 *                               12 → 15 min
 *                               20 → 60 min
 */
final class RateLimiter
{
    private const WINDOW_MINUTES = 60;
    public const CAPTCHA_AFTER = 3;
    private const STEPS = [20 => 60, 12 => 15, 8 => 5, 5 => 1];

    /** @return array{failures:int,captcha:bool,blocked_seconds:int} */
    public static function status(string $kind, string $ip, ?string $email): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);
        $byIp = (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE kind = ? AND ip = ? AND succeeded = 0 AND created_at >= ?',
            [$kind, $ip, $since]
        );
        $byEmail = 0;
        $lastFailure = null;
        if ($email !== null && $email !== '') {
            $byEmail = (int) Database::scalar(
                'SELECT COUNT(*) FROM login_attempts WHERE kind = ? AND email = ? AND succeeded = 0 AND created_at >= ?',
                [$kind, $email, $since]
            );
        }
        $failures = max($byIp, $byEmail);
        $blockedSeconds = 0;
        if ($failures >= 5) {
            $row = Database::selectOne(
                'SELECT MAX(created_at) AS last_at FROM login_attempts WHERE kind = ? AND succeeded = 0 AND created_at >= ? AND (ip = ? OR email = ?)',
                [$kind, $since, $ip, $email ?? '']
            );
            $lastFailure = $row['last_at'] ?? null;
            $minutes = 0;
            foreach (self::STEPS as $threshold => $blockMinutes) {
                if ($failures >= $threshold) {
                    $minutes = $blockMinutes;
                    break;
                }
            }
            if ($lastFailure !== null && $minutes > 0) {
                $until = strtotime($lastFailure . ' UTC') + $minutes * 60;
                $blockedSeconds = max(0, $until - time());
            }
        }
        return [
            'failures'        => $failures,
            'captcha'         => $failures >= self::CAPTCHA_AFTER,
            'blocked_seconds' => $blockedSeconds,
        ];
    }

    public static function record(string $kind, string $ip, ?string $email, bool $succeeded, string $userAgent = ''): void
    {
        Database::insert('login_attempts', [
            'kind'       => $kind,
            'ip'         => $ip,
            'email'      => $email !== null ? mb_substr(mb_strtolower($email), 0, 190) : null,
            'succeeded'  => $succeeded ? 1 : 0,
            'user_agent' => mb_substr($userAgent, 0, 255),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Após sucesso, as falhas anteriores da conta deixam de contar (evita bloqueio residual). */
    public static function clear(string $kind, ?string $email): void
    {
        if ($email === null || $email === '') {
            return;
        }
        Database::execute('DELETE FROM login_attempts WHERE kind = ? AND email = ? AND succeeded = 0', [$kind, mb_strtolower($email)]);
    }

    public static function humanWait(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' segundo' . ($seconds === 1 ? '' : 's');
        }
        $minutes = (int) ceil($seconds / 60);
        return $minutes . ' minuto' . ($minutes === 1 ? '' : 's');
    }
}
