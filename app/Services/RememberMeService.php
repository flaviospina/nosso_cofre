<?php
// app/Services/RememberMeService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Models\User;

/**
 * "Lembrar-me" com par selector/validator: o cookie leva "selector:validator"; o banco guarda o selector
 * e só o hash do validator. A cada uso o validator é trocado (token rotativo); roubo do banco não dá acesso.
 */
final class RememberMeService
{
    public const COOKIE = 'nc_remember';

    public static function issue(int $userId): void
    {
        $selector = Crypto::randomUrlToken(18); // 24 caracteres
        $validator = Crypto::randomUrlToken(32);
        $days = max(1, (int) Config::get('security.remember_days', 30));
        $request = App::request();
        Database::insert('remember_tokens', [
            'user_id'        => $userId,
            'selector'       => $selector,
            'validator_hash' => Crypto::hashToken($validator),
            'device_label'   => $request?->deviceLabel(),
            'ip'             => $request?->ip(),
            'expires_at'     => gmdate('Y-m-d H:i:s', time() + $days * 86400),
            'created_at'     => gmdate('Y-m-d H:i:s'),
        ]);
        self::setCookie($selector . ':' . $validator, time() + $days * 86400);
    }

    /** Tenta autenticar pelo cookie. Devolve o usuário ou null. */
    /** @return array<string,mixed>|null */
    public static function attempt(Request $request): ?array
    {
        $cookie = $request->cookie(self::COOKIE);
        if ($cookie === null || !str_contains($cookie, ':')) {
            return null;
        }
        [$selector, $validator] = explode(':', $cookie, 2);
        if (!preg_match('/^[\w\-]{20,32}$/', $selector) || !preg_match('/^[\w\-]{40,48}$/', $validator)) {
            self::clearCookie();
            return null;
        }
        $row = Database::selectOne('SELECT * FROM remember_tokens WHERE selector = ?', [$selector]);
        if ($row === null) {
            self::clearCookie();
            return null;
        }
        if (strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            Database::execute('DELETE FROM remember_tokens WHERE id = ?', [(int) $row['id']]);
            self::clearCookie();
            return null;
        }
        if (!hash_equals((string) $row['validator_hash'], Crypto::hashToken($validator))) {
            // Selector válido com validator errado = cookie possivelmente roubado: invalida todos os tokens do usuário
            Logger::security('Cookie lembrar-me com validator inválido; tokens do usuário revogados', ['user_id' => $row['user_id'], 'ip' => $request->ip()]);
            Database::execute('DELETE FROM remember_tokens WHERE user_id = ?', [(int) $row['user_id']]);
            self::clearCookie();
            return null;
        }
        $user = (new User())->find((int) $row['user_id']);
        if ($user === null || ($user['status'] ?? '') !== 'active' || empty($user['email_verified_at'])) {
            self::clearCookie();
            return null;
        }
        // Rotaciona o validator
        $newValidator = Crypto::randomUrlToken(32);
        Database::execute('UPDATE remember_tokens SET validator_hash = ?, last_used_at = ?, ip = ? WHERE id = ?', [
            Crypto::hashToken($newValidator),
            gmdate('Y-m-d H:i:s'),
            $request->ip(),
            (int) $row['id'],
        ]);
        self::setCookie($selector . ':' . $newValidator, strtotime((string) $row['expires_at'] . ' UTC'));
        return $user;
    }

    /** Remove o token do cookie atual (logout neste aparelho). */
    public static function forgetCurrent(?int $userId): void
    {
        $request = App::request();
        $cookie = $request?->cookie(self::COOKIE);
        if ($cookie !== null && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            Database::execute('DELETE FROM remember_tokens WHERE selector = ?', [$selector]);
        }
        self::clearCookie();
    }

    /** Revoga todos os tokens (troca de senha, "encerrar outras sessões", exclusão). */
    public static function revokeAll(int $userId): void
    {
        Database::execute('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
    }

    private static function setCookie(string $value, int $expires): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $base = (string) Config::get('app.base_path', '');
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => $base === '' ? '/' : $base . '/',
            'secure'   => str_starts_with((string) Config::get('app.url', ''), 'https://') || (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax', // Lax (não Strict) para o cookie valer ao abrir um link de e-mail
        ]);
    }

    private static function clearCookie(): void
    {
        self::setCookie('', time() - 86400);
    }
}
