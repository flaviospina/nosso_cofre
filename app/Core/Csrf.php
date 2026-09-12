<?php
// app/Core/Csrf.php
declare(strict_types=1);

namespace App\Core;

/**
 * Token CSRF por sessão. Aceito no campo "_token" (formulários) ou no cabeçalho "X-CSRF-Token" (fetch/JSON).
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (!isset($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY]) || strlen($_SESSION[self::KEY]) !== 64) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function rotate(): void
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }

    public static function validate(?string $token): bool
    {
        if ($token === null || $token === '' || !isset($_SESSION[self::KEY])) {
            return false;
        }
        return hash_equals((string) $_SESSION[self::KEY], $token);
    }

    public static function validateRequest(Request $request): bool
    {
        $token = $request->input('_token');
        if (!is_string($token) || $token === '') {
            $token = $request->header('X-CSRF-Token');
        }
        return self::validate(is_string($token) ? $token : null);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}
