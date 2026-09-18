<?php
// app/Core/Auth.php
declare(strict_types=1);

namespace App\Core;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Services\RememberMeService;

/**
 * Estado de autenticação da requisição: usuário logado, lar ativo e papel.
 * Os fluxos (cadastro, login, 2FA, recuperação) ficam nos Services; aqui está só o que o núcleo usa.
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
    public const ROLE_LABELS = ['owner' => 'Responsável', 'admin' => 'Administrador', 'member' => 'Membro', 'viewer' => 'Somente leitura'];

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$user !== false) {
            return self::$user;
        }
        if (!Database::isConfigured()) {
            return self::$user = null;
        }
        $id = (int) Session::get('user_id', 0);
        if ($id <= 0 && PHP_SAPI !== 'cli') {
            // Sem sessão: tenta o cookie "lembrar-me"
            $request = App::request();
            if ($request !== null) {
                $remembered = RememberMeService::attempt($request);
                if ($remembered !== null) {
                    self::login($remembered, false, true);
                    $id = (int) $remembered['id'];
                    \App\Services\AuditService::log('user.login_remembered', 'user', $id, null, ['device' => $request->deviceLabel()], $id, null);
                }
            }
        }
        if ($id <= 0) {
            return self::$user = null;
        }
        $user = (new User())->find($id);
        if ($user === null || in_array($user['status'] ?? 'active', ['anonymized', 'blocked'], true)) {
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

    /**
     * Abre a sessão de um usuário já verificado (senha e 2FA conferidos pelo AuthService).
     * @param array<string,mixed> $user
     */
    public static function login(array $user, bool $remember = false, bool $viaRememberCookie = false): void
    {
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::set('login_at', time());
        Session::set('via_remember', $viaRememberCookie);
        Session::forget('2fa_pending_user_id');
        Session::setIdleMinutes((int) ($user['session_idle_minutes'] ?? Config::get('security.session_idle_minutes', 30)));
        Csrf::rotate();
        self::refresh();
        $member = HouseholdMember::firstActiveForUser((int) $user['id']);
        Session::set('household_id', $member === null ? null : (int) $member['household_id']);
        if ($remember) {
            RememberMeService::issue((int) $user['id']);
        }
    }

    public static function logout(): void
    {
        $userId = self::id();
        RememberMeService::forgetCurrent($userId);
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

    // --- 2FA pendente (entre a senha e o código) ---

    public static function setTwoFactorPending(int $userId): void
    {
        Session::regenerate();
        Session::set('2fa_pending_user_id', $userId);
        Session::set('2fa_pending_at', time());
    }

    /** @return array<string,mixed>|null */
    public static function twoFactorPendingUser(): ?array
    {
        $id = (int) Session::get('2fa_pending_user_id', 0);
        $at = (int) Session::get('2fa_pending_at', 0);
        if ($id <= 0 || time() - $at > 600) {
            return null;
        }
        return (new User())->find($id);
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

    /** Define/troca o lar ativo (só se o usuário for membro ativo dele). */
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

    /** Registro de membro (papel etc.) do usuário no lar ativo. */
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

    public static function hasTwoFactor(): bool
    {
        $user = self::user();
        return $user !== null && !empty($user['totp_enabled_at']);
    }

    /** Owner/admin de lar familiar precisa de 2FA ativo (regra do §3.1). */
    public static function twoFactorRequired(): bool
    {
        return self::isFamily() && self::canManage() && !self::hasTwoFactor();
    }

    /** Limpa o cache estático (após alterações no próprio usuário/lar). */
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
