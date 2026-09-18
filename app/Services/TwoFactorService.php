<?php
// app/Services/TwoFactorService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Session;
use App\Core\Totp;
use App\Models\User;

/**
 * Verificação em duas etapas (TOTP) com códigos de recuperação.
 * O segredo fica criptografado em users.totp_secret; os códigos de recuperação, em hash.
 */
final class TwoFactorService
{
    private const PENDING_KEY = '2fa_setup_secret';

    /** Inicia a configuração: segredo provisório na sessão até o usuário confirmar um código. */
    /** @param array<string,mixed> $user
     *  @return array{secret:string,uri:string,formatted:string} */
    public static function beginSetup(array $user): array
    {
        $secret = Session::get(self::PENDING_KEY);
        if (!is_string($secret) || $secret === '') {
            $secret = Totp::generateSecret();
            Session::set(self::PENDING_KEY, $secret);
        }
        return [
            'secret'    => $secret,
            'uri'       => Totp::provisioningUri($secret, (string) $user['email'], (string) Config::get('app.name', 'Nosso Cofre')),
            'formatted' => Totp::formatSecret($secret),
        ];
    }

    /**
     * Confirma o código, grava o segredo e gera códigos de recuperação (devolvidos em claro UMA vez).
     * @param array<string,mixed> $user
     * @return list<string>|null null quando o código não confere
     */
    public static function confirmSetup(array $user, string $code): ?array
    {
        $secret = Session::get(self::PENDING_KEY);
        if (!is_string($secret) || $secret === '') {
            return null;
        }
        $counter = Totp::verify($secret, $code);
        if ($counter === null) {
            return null;
        }
        $codes = Totp::generateRecoveryCodes();
        (new User())->update((int) $user['id'], [
            'totp_secret'         => $secret,
            'totp_enabled_at'     => gmdate('Y-m-d H:i:s'),
            'totp_recovery_codes' => array_map(static fn(string $c): string => self::hashRecoveryCode($c), $codes),
            'totp_last_counter'   => $counter,
        ]);
        Session::forget(self::PENDING_KEY);
        Session::regenerate(); // elevação de privilégio
        AuditService::log('user.2fa_enabled', 'user', (int) $user['id']);
        return $codes;
    }

    /** @param array<string,mixed> $user */
    public static function disable(array $user): void
    {
        (new User())->update((int) $user['id'], [
            'totp_secret'         => null,
            'totp_enabled_at'     => null,
            'totp_recovery_codes' => null,
            'totp_last_counter'   => null,
        ]);
        AuditService::log('user.2fa_disabled', 'user', (int) $user['id']);
    }

    /** Verifica um código TOTP no login (com proteção contra reuso). */
    /** @param array<string,mixed> $user */
    public static function verifyCode(array $user, string $code): bool
    {
        $secret = (string) ($user['totp_secret'] ?? '');
        if ($secret === '') {
            return false;
        }
        $last = isset($user['totp_last_counter']) ? (int) $user['totp_last_counter'] : null;
        $counter = Totp::verify($secret, $code, null, $last);
        if ($counter === null) {
            return false;
        }
        // Atômico: dois pedidos simultâneos com o mesmo código não passam os dois
        $updated = Database::execute('UPDATE users SET totp_last_counter = ? WHERE id = ? AND (totp_last_counter IS NULL OR totp_last_counter < ?)', [$counter, (int) $user['id'], $counter]);
        return $updated > 0;
    }

    /** Consome um código de recuperação. */
    /** @param array<string,mixed> $user */
    public static function verifyRecoveryCode(array $user, string $code): bool
    {
        $hashes = $user['totp_recovery_codes'] ?? null;
        if (!is_array($hashes) || $hashes === []) {
            return false;
        }
        $given = self::hashRecoveryCode($code);
        $legacy = Crypto::hashToken(Totp::normalizeRecoveryCode($code)); // códigos gerados antes da fase 8
        foreach ($hashes as $index => $hash) {
            if (hash_equals((string) $hash, $given) || hash_equals((string) $hash, $legacy)) {
                unset($hashes[$index]);
                (new User())->update((int) $user['id'], ['totp_recovery_codes' => array_values($hashes)]);
                AuditService::log('user.2fa_recovery_used', 'user', (int) $user['id'], null, ['remaining' => count($hashes)], (int) $user['id'], null);
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $user
     *  @return list<string> */
    public static function regenerateRecoveryCodes(array $user): array
    {
        $codes = Totp::generateRecoveryCodes();
        (new User())->update((int) $user['id'], [
            'totp_recovery_codes' => array_map(static fn(string $c): string => self::hashRecoveryCode($c), $codes),
        ]);
        AuditService::log('user.2fa_recovery_regenerated', 'user', (int) $user['id']);
        return $codes;
    }

    /** HMAC com a chave do app: um vazamento do banco não permite quebrar os códigos offline. */
    public static function hashRecoveryCode(string $code): string
    {
        return Crypto::sign('recovery:' . Totp::normalizeRecoveryCode($code));
    }

    /** @param array<string,mixed> $user */
    public static function remainingRecoveryCodes(array $user): int
    {
        $hashes = $user['totp_recovery_codes'] ?? null;
        return is_array($hashes) ? count($hashes) : 0;
    }
}
