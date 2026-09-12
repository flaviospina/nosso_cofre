<?php
// app/Core/Auth.php
declare(strict_types=1);

namespace App\Core;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;

/**
 * Estado de autenticação da requisição: usuário logado, lar ativo e papel.
 * Fluxos de login/cadastro/2FA/lembrar-me vivem no AuthController e no AuthService (fase 2);
 * aqui fica só o que o núcleo precisa (middleware, Model base, views).
 */
final class Auth
{
    /** @var array<string,mixed>|null|false false = ainda não carregado */
    private static array|null|false $user = false;
    /** @var array<string,mixed>|null|false */
    private static array|null|false $household = false;
    /** @var array<string,mixed>|null|false */
    private static array|null|false $member = false;

    public const ROLES = ['owner', 'admin', 'member', 'viewer'];

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$user !== false) {
            return self::$user;
        }
        $id = (int) Session::get('user_id', 0);
        if ($id <= 0 || !Database::isConfigured()) {
            return self::$user = null;
        }
        $user = (new User())->find($id);
        if ($user === null || ($user['status'] ?? 'active') === 'anonymized' || ($user['status'] ?? '') === 'blocked') {
            self::forceLogout();
            return self::$user = null;
        }
        return self::$user = $user;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user === null ? null : (int) $user['id'];
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Login já validado (senha e 2FA conferidos pelo AuthService). */
    /** @param array<string,mixed> $user */
    public static function login(array $user): void
    {
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::set('login_at', time());
        Session::setIdleMinutes((int) ($user['session_idle_minutes'] ?? Config::get('security.session_idle_minutes', 30)));
        Csrf::rotate();
        self::$user = false;
        self::$household = false;
        self::$member = false;
        // Lar ativo: o primeiro em que o usuário é membro ativo (um lar por usuário nesta versão)
        $member = HouseholdMember::firstActiveForUser((int) $user['id']);
        Session::set('household_id', $member === null ? null : (int) $member['household_id']);
    }

    public static function logout(): void
    {
        self::forceLogout();
    }

    private static function forceLogout(): void
    {
        self::$user = null;
        self::$household = null;
        self::$member = null;
        Session::destroy();
        if (PHP_SAPI !== 'cli') {
            session_start();
        }
    }

    /** Lar ativo da sessão. */
    /** @return array<string,mixed>|null */
    public static function household(): ?array
    {
        if (self::$household !== false) {
            return self::$household;
        }
        $id = self::householdId();
        if ($id === null) {
            return self::$household = null;
        }
        return self::$household = Household::findById($id);
    }

    public static function householdId(): ?int
    {
        if (!self::check()) {
            return null;
        }
        $id = Session::get('household_id');
        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /** Troca o lar ativo (só se o usuário for membro ativo dele). */
    public static function switchHousehold(int $householdId): bool
    {
        $userId = self::id();
        if ($userId === null) {
            return false;
        }
        $member = HouseholdMember::findActive($householdId, $userId);
        if ($member === null) {
            return false;
        }
        Session::set('household_id', $householdId);
        self::$household = false;
        self::$member = false;
        return true;
    }

    /** Registro de membro (papel, cor, etc.) do usuário no lar ativo. */
    /** @return array<string,mixed>|null */
    public static function member(): ?array
    {
        if (self::$member !== false) {
            return self::$member;
        }
        $householdId = self::householdId();
        $userId = self::id();
        if ($householdId === null || $userId === null) {
            return self::$member = null;
        }
        return self::$member = HouseholdMember::findActive($householdId, $userId);
    }

    public static function role(): ?string
    {
        $member = self::member();
        return $member === null ? null : (string) $member['role'];
    }

    /** @param string ...$roles */
    public static function hasRole(string ...$roles): bool
    {
        $role = self::role();
        return $role !== null && in_array($role, $roles, true);
    }

    public static function isOwner(): bool
    {
        return self::hasRole('owner');
    }

    public static function canManage(): bool
    {
        return self::hasRole('owner', 'admin');
    }

    public static function canWrite(): bool
    {
        return self::hasRole('owner', 'admin', 'member');
    }

    public static function isFamily(): bool
    {
        $h = self::household();
        return $h !== null && ($h['type'] ?? '') === 'family';
    }

    /** Limpa o cache estático (usado em testes e após alterações no próprio usuário). */
    public static function refresh(): void
    {
        self::$user = false;
        self::$household = false;
        self::$member = false;
    }

    // --- Senhas ---

    /** Argon2id quando o PHP do servidor suporta; senão bcrypt (custo 12). */
    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    public static function passwordNeedsRehash(string $hash): bool
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]);
        }
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}
