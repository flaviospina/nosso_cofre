<?php
// app/Services/AuthService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\DatabaseSessionHandler;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Mailer;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Models\Consent;
use App\Models\Invitation;
use App\Models\User;

/**
 * Cadastro, confirmação de e-mail, login (com limite de tentativas e aviso de aparelho novo),
 * recuperação e troca de senha.
 */
final class AuthService
{
    public const VERIFY_TTL_HOURS = 24;
    public const RESET_TTL_MINUTES = 60;

    /**
     * Cria a conta (não verificada), grava consentimentos e envia o e-mail de confirmação.
     * @param array{name:string,email:string,password:string,terms_version:string,privacy_version:string} $data
     * @return array<string,mixed> usuário criado
     */
    public static function register(array $data, Request $request): array
    {
        $users = new User();
        $now = gmdate('Y-m-d H:i:s');
        $userId = Database::transaction(static function () use ($users, $data, $now): int {
            $id = $users->create([
                'name'               => $data['name'],
                'email'              => mb_strtolower($data['email']),
                'password_hash'      => Auth::hashPassword($data['password']),
                'color'              => self::randomColor(),
                'timezone'           => (string) Config::get('app.timezone', 'America/Sao_Paulo'),
                'adult_confirmed_at' => $now,
                'status'             => 'active',
            ]);
            Consent::record($id, 'terms', true, $data['terms_version']);
            Consent::record($id, 'privacy', true, $data['privacy_version']);
            Consent::record($id, 'adult', true, null);
            // E-mails transacionais (confirmação, recuperação, segurança) são necessários ao serviço: base legal = execução de contrato
            Consent::record($id, 'transactional_email', true, null);
            return $id;
        });
        $user = $users->find($userId) ?? [];
        AuditService::log('user.register', 'user', $userId, null, ['name' => $data['name'], 'email' => $data['email']], $userId, null);
        RateLimiter::record('register', $request->ip(), $data['email'], true, $request->userAgent());
        self::sendVerification($user);
        return $user;
    }

    /** Gera token de uso único (24 h) e envia o e-mail de confirmação. */
    /** @param array<string,mixed> $user */
    public static function sendVerification(array $user): void
    {
        $token = Crypto::randomUrlToken(32);
        Database::execute('UPDATE email_verifications SET verified_at = verified_at WHERE user_id = ?', [(int) $user['id']]);
        Database::insert('email_verifications', [
            'user_id'    => (int) $user['id'],
            'email'      => (string) $user['email'],
            'token_hash' => Crypto::hashToken($token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::VERIFY_TTL_HOURS * 3600),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        Mailer::send((string) $user['email'], (string) $user['name'], 'Confirme seu e-mail no Nosso Cofre', 'verify-email', [
            'name' => $user['name'],
            'url'  => absolute_url('/confirmar-email/' . $token),
            'hours' => self::VERIFY_TTL_HOURS,
        ], 'transactional', (int) $user['id']);
    }

    /**
     * Confirma o e-mail pelo token. Devolve o usuário ou null se o token for inválido/expirado.
     * Aceita automaticamente convites pendentes endereçados a esse e-mail.
     * @return array<string,mixed>|null
     */
    public static function verifyEmail(string $token): ?array
    {
        $row = Database::selectOne(
            'SELECT * FROM email_verifications WHERE token_hash = ? AND verified_at IS NULL AND expires_at > ?',
            [Crypto::hashToken($token), gmdate('Y-m-d H:i:s')]
        );
        if ($row === null) {
            return null;
        }
        $users = new User();
        $user = $users->find((int) $row['user_id']);
        if ($user === null) {
            return null;
        }
        $now = gmdate('Y-m-d H:i:s');
        Database::transaction(static function () use ($row, $users, $user, $now): void {
            Database::execute('UPDATE email_verifications SET verified_at = ? WHERE id = ?', [$now, (int) $row['id']]);
            $users->update((int) $user['id'], ['email' => (string) $row['email'], 'email_verified_at' => $now]);
        });
        AuditService::log('user.email_verified', 'user', (int) $user['id'], null, ['email' => $row['email']], (int) $user['id'], null);
        foreach (Invitation::pendingForEmail((string) $row['email']) as $invitation) {
            InvitationService::accept($invitation, (int) $user['id']);
        }
        return $users->find((int) $user['id']);
    }

    /**
     * Valida e-mail e senha. Devolve:
     *  ['ok' => true, 'user' => ..., 'two_factor' => bool]
     *  ['ok' => false, 'reason' => 'blocked'|'captcha'|'invalid'|'unverified', 'wait' => segundos]
     * @return array<string,mixed>
     */
    public static function attemptLogin(string $email, string $password, Request $request, ?string $captchaToken, ?string $captchaAnswer): array
    {
        $email = mb_strtolower(trim($email));
        $status = RateLimiter::status('login', $request->ip(), $email);
        if ($status['blocked_seconds'] > 0) {
            return ['ok' => false, 'reason' => 'blocked', 'wait' => $status['blocked_seconds']];
        }
        if ($status['captcha'] && !\App\Core\Captcha::verify($captchaToken, $captchaAnswer)) {
            RateLimiter::record('login', $request->ip(), $email, false, $request->userAgent());
            return ['ok' => false, 'reason' => 'captcha'];
        }
        $user = (new User())->findByEmail($email);
        // Tempo constante aproximado: verifica um hash falso quando o usuário não existe
        $hash = $user['password_hash'] ?? '$2y$12$C6UzMDM.H6dfI/f/IKcEeO4JcS.9UgN2R/4mJq6mQ0pV9i9uYw0uK';
        $valid = Auth::verifyPassword($password, (string) $hash) && $user !== null;
        if (!$valid || in_array($user['status'] ?? '', ['anonymized', 'blocked'], true)) {
            RateLimiter::record('login', $request->ip(), $email, false, $request->userAgent());
            Logger::security('Login inválido', ['email' => $email, 'ip' => $request->ip()]);
            return ['ok' => false, 'reason' => 'invalid'];
        }
        if (empty($user['email_verified_at'])) {
            return ['ok' => false, 'reason' => 'unverified', 'user' => $user];
        }
        if (Auth::passwordNeedsRehash((string) $user['password_hash'])) {
            (new User())->update((int) $user['id'], ['password_hash' => Auth::hashPassword($password)]);
        }
        if (!empty($user['totp_enabled_at'])) {
            Auth::setTwoFactorPending((int) $user['id']);
            return ['ok' => true, 'user' => $user, 'two_factor' => true];
        }
        return ['ok' => true, 'user' => $user, 'two_factor' => false];
    }

    /** Conclui o login (após senha e, se houver, 2FA): sessão, registro, aviso de aparelho novo. */
    /** @param array<string,mixed> $user */
    public static function finalizeLogin(array $user, bool $remember, Request $request): void
    {
        $deviceHash = self::deviceHash($request);
        $isNewDevice = !empty($user['last_login_at']) && !self::deviceKnown((string) $user['email'], $deviceHash);

        Auth::login($user, $remember);
        (new User())->update((int) $user['id'], ['last_login_at' => gmdate('Y-m-d H:i:s'), 'last_login_ip' => $request->ip()]);
        RateLimiter::clear('login', (string) $user['email']);
        Database::insert('login_attempts', [
            'kind'        => 'login',
            'ip'          => $request->ip(),
            'email'       => (string) $user['email'],
            'succeeded'   => 1,
            'user_agent'  => $request->userAgent(),
            'device_hash' => $deviceHash,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
        AuditService::log('user.login', 'user', (int) $user['id'], null, ['device' => $request->deviceLabel(), 'remember' => $remember], (int) $user['id'], null);

        if ($isNewDevice) {
            Logger::security('Login de aparelho novo', ['user_id' => $user['id'], 'ip' => $request->ip()]);
            Mailer::send((string) $user['email'], (string) $user['name'], 'Novo acesso à sua conta do Nosso Cofre', 'new-device', [
                'name'   => $user['name'],
                'device' => $request->deviceLabel(),
                'ip'     => $request->ip(),
                'when'   => (new \DateTimeImmutable('now', new \DateTimeZone((string) ($user['timezone'] ?? 'America/Sao_Paulo'))))->format('d/m/Y H:i'),
                'sessionsUrl' => absolute_url('/conta/sessoes'),
            ], 'security', (int) $user['id']);
        }
    }

    public static function deviceHash(Request $request): string
    {
        // IP + família do navegador/SO (não o user-agent inteiro, que muda a cada atualização do navegador)
        return hash('sha256', $request->ip() . '|' . $request->deviceLabel());
    }

    private static function deviceKnown(string $email, string $deviceHash): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND device_hash = ? AND succeeded = 1',
            [$email, $deviceHash]
        ) > 0;
    }

    /** Recuperação de senha: sempre responde igual, exista ou não o e-mail. */
    public static function requestPasswordReset(string $email, Request $request): void
    {
        $email = mb_strtolower(trim($email));
        RateLimiter::record('reset', $request->ip(), $email, true, $request->userAgent());
        $user = (new User())->findByEmail($email);
        if ($user === null || ($user['status'] ?? '') !== 'active') {
            return;
        }
        $token = Crypto::randomUrlToken(32);
        Database::insert('password_resets', [
            'user_id'    => (int) $user['id'],
            'token_hash' => Crypto::hashToken($token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::RESET_TTL_MINUTES * 60),
            'ip'         => $request->ip(),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        Mailer::send((string) $user['email'], (string) $user['name'], 'Redefinição de senha do Nosso Cofre', 'password-reset', [
            'name'    => $user['name'],
            'url'     => absolute_url('/redefinir-senha/' . $token),
            'minutes' => self::RESET_TTL_MINUTES,
        ], 'transactional', (int) $user['id']);
    }

    /** @return array<string,mixed>|null usuário dono do token válido */
    public static function findResetUser(string $token): ?array
    {
        $row = Database::selectOne(
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [Crypto::hashToken($token), gmdate('Y-m-d H:i:s')]
        );
        if ($row === null) {
            return null;
        }
        return (new User())->find((int) $row['user_id']);
    }

    public static function resetPassword(string $token, string $newPassword): bool
    {
        $row = Database::selectOne(
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [Crypto::hashToken($token), gmdate('Y-m-d H:i:s')]
        );
        if ($row === null) {
            return false;
        }
        $userId = (int) $row['user_id'];
        Database::transaction(static function () use ($row, $userId, $newPassword): void {
            Database::execute('UPDATE password_resets SET used_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), (int) $row['id']]);
            (new User())->update($userId, ['password_hash' => Auth::hashPassword($newPassword)]);
        });
        // Troca de senha derruba todas as sessões e tokens "lembrar-me"
        DatabaseSessionHandler::destroyAllForUser($userId);
        RememberMeService::revokeAll($userId);
        AuditService::log('user.password_reset', 'user', $userId, null, null, $userId, null);
        return true;
    }

    /** @param array<string,mixed> $user */
    public static function changePassword(array $user, string $currentPassword, string $newPassword): bool
    {
        if (!Auth::verifyPassword($currentPassword, (string) $user['password_hash'])) {
            return false;
        }
        (new User())->update((int) $user['id'], ['password_hash' => Auth::hashPassword($newPassword)]);
        DatabaseSessionHandler::destroyOthers((int) $user['id'], session_id());
        RememberMeService::revokeAll((int) $user['id']);
        AuditService::log('user.password_changed', 'user', (int) $user['id']);
        return true;
    }

    public static function randomColor(): string
    {
        $palette = ['#0d6efd', '#d63384', '#198754', '#fd7e14', '#6f42c1', '#20c997', '#dc3545', '#0dcaf0', '#6610f2', '#ffc107'];
        return $palette[array_rand($palette)];
    }
}
