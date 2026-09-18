<?php
// app/Controllers/AccountController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\DatabaseSessionHandler;
use App\Core\Response;
use App\Core\Session;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\RememberMeService;
use App\Services\TwoFactorService;

/** Conta do usuário: perfil, senha, 2FA, sessões ativas, atividade. */
final class AccountController extends Controller
{
    public const TIMEZONES = ['America/Sao_Paulo', 'America/Manaus', 'America/Belem', 'America/Fortaleza', 'America/Recife', 'America/Bahia', 'America/Cuiaba', 'America/Campo_Grande', 'America/Porto_Velho', 'America/Rio_Branco', 'America/Noronha', 'America/Boa_Vista', 'America/Araguaina', 'America/Maceio'];

    public function index(): Response
    {
        return $this->view('account/index', ['title' => 'Minha conta', 'user' => Auth::user(), 'timezones' => self::TIMEZONES]);
    }

    public function update(): Response
    {
        $user = Auth::user() ?? [];
        $data = $this->validate([
            'name'                 => 'required|min:2|max:120',
            'color'                => 'required|color',
            'timezone'             => 'required|timezone',
            'session_idle_minutes' => 'required|in:15,30,60',
        ], ['name' => 'nome', 'color' => 'cor', 'timezone' => 'fuso horário', 'session_idle_minutes' => 'tempo de sessão'], 'account.index');
        $before = ['name' => $user['name'], 'color' => $user['color'], 'timezone' => $user['timezone'], 'session_idle_minutes' => $user['session_idle_minutes']];
        (new User())->update((int) $user['id'], [
            'name'                 => $data['name'],
            'color'                => $data['color'],
            'timezone'             => $data['timezone'],
            'session_idle_minutes' => (int) $data['session_idle_minutes'],
        ]);
        Session::setIdleMinutes((int) $data['session_idle_minutes']);
        AuditService::log('user.profile_updated', 'user', (int) $user['id'], $before, $data);
        Auth::refresh();
        $this->flash('success', 'Dados atualizados.');
        return $this->redirectRoute('account.index');
    }

    public function passwordForm(): Response
    {
        return $this->view('account/password', ['title' => 'Alterar senha']);
    }

    public function updatePassword(): Response
    {
        $user = Auth::user() ?? [];
        $data = $this->validate([
            'current_password' => 'required',
            'password'         => 'required|password|confirmed|different:current_password',
        ], ['current_password' => 'senha atual', 'password' => 'nova senha'], 'account.password');
        if (!AuthService::changePassword($user, (string) $data['current_password'], (string) $data['password'])) {
            Session::flashErrors(['current_password' => ['A senha atual não confere.']]);
            return $this->redirectRoute('account.password');
        }
        $this->flash('success', 'Senha alterada. As outras sessões foram encerradas.');
        return $this->redirectRoute('account.index');
    }

    // --- 2FA ---

    public function twoFactor(): Response
    {
        $user = Auth::user() ?? [];
        $enabled = !empty($user['totp_enabled_at']);
        $setup = $enabled ? null : TwoFactorService::beginSetup($user);
        $freshCodes = Session::get('2fa_fresh_codes');
        Session::forget('2fa_fresh_codes');
        return $this->view('account/two-factor', [
            'title'      => 'Verificação em duas etapas',
            'enabled'    => $enabled,
            'required'   => Auth::twoFactorRequired(),
            'setup'      => $setup,
            'freshCodes' => is_array($freshCodes) ? $freshCodes : null,
            'remaining'  => TwoFactorService::remainingRecoveryCodes($user),
        ]);
    }

    public function enableTwoFactor(): Response
    {
        $user = Auth::user() ?? [];
        $data = $this->validate(['code' => 'required|digits:6'], ['code' => 'código'], 'account.two_factor');
        $codes = TwoFactorService::confirmSetup($user, (string) $data['code']);
        if ($codes === null) {
            Session::flashErrors(['code' => ['Código inválido. Confira se o aplicativo leu o QR code certo e se o relógio do celular está em automático.']]);
            return $this->redirectRoute('account.two_factor');
        }
        Session::set('2fa_fresh_codes', $codes);
        Auth::refresh();
        $this->flash('success', 'Verificação em duas etapas ativada. Guarde os códigos de recuperação abaixo.');
        return $this->redirectRoute('account.two_factor');
    }

    public function disableTwoFactor(): Response
    {
        $user = Auth::user() ?? [];
        $data = $this->validate(['password' => 'required', 'code' => 'required|min:6|max:20'], ['password' => 'senha', 'code' => 'código'], 'account.two_factor');
        if (!Auth::verifyPassword((string) $data['password'], (string) $user['password_hash']) || !TwoFactorService::verifyCode($user, (string) $data['code'])) {
            Session::flashErrors(['code' => ['Senha ou código inválidos (um código já utilizado não vale de novo; aguarde o próximo).']]);
            return $this->redirectRoute('account.two_factor');
        }
        if (Auth::twoFactorRequired() || (Auth::isFamily() && Auth::canManage())) {
            $this->flash('warning', 'Responsável ou administrador de lar familiar não pode desativar o 2FA. Transfira a função antes, se necessário.');
            return $this->redirectRoute('account.two_factor');
        }
        TwoFactorService::disable($user);
        Auth::refresh();
        $this->flash('success', 'Verificação em duas etapas desativada.');
        return $this->redirectRoute('account.two_factor');
    }

    public function regenerateRecoveryCodes(): Response
    {
        $user = Auth::user() ?? [];
        $data = $this->validate(['code' => 'required|digits:6'], ['code' => 'código'], 'account.two_factor');
        if (empty($user['totp_enabled_at']) || !TwoFactorService::verifyCode($user, (string) $data['code'])) {
            Session::flashErrors(['code' => ['Código inválido.']]);
            return $this->redirectRoute('account.two_factor');
        }
        Session::set('2fa_fresh_codes', TwoFactorService::regenerateRecoveryCodes($user));
        $this->flash('success', 'Novos códigos de recuperação gerados. Os antigos não valem mais.');
        return $this->redirectRoute('account.two_factor');
    }

    // --- Sessões ---

    public function sessions(): Response
    {
        $userId = (int) Auth::id();
        $sessions = DatabaseSessionHandler::forUser($userId);
        $tokens = \App\Core\Database::select('SELECT id, device_label, ip, created_at, last_used_at, expires_at FROM remember_tokens WHERE user_id = ? ORDER BY created_at DESC', [$userId]);
        return $this->view('account/sessions', [
            'title'     => 'Sessões ativas',
            'sessions'  => $sessions,
            'current'   => session_id(),
            'tokens'    => $tokens,
        ]);
    }

    public function revokeOtherSessions(): Response
    {
        $userId = (int) Auth::id();
        $count = DatabaseSessionHandler::destroyOthers($userId, session_id());
        RememberMeService::revokeAll($userId);
        AuditService::log('user.sessions_revoked', 'user', $userId, null, ['sessions' => $count]);
        $this->flash('success', 'Outras sessões encerradas (' . $count . '). Os acessos "lembrar-me" também foram revogados.');
        return $this->redirectRoute('account.sessions');
    }

    // --- Atividade ---

    public function activity(): Response
    {
        $userId = (int) Auth::id();
        $page = max(1, (int) ($this->request->query('pagina') ?? 1));
        $perPage = 25;
        $logs = new AuditLog();
        $total = $logs->countForUser($userId);
        return $this->view('account/activity', [
            'title'   => 'Minha atividade',
            'entries' => $logs->forUser($userId, $perPage, ($page - 1) * $perPage),
            'page'    => $page,
            'pages'   => (int) max(1, ceil($total / $perPage)),
            'total'   => $total,
        ]);
    }
}
