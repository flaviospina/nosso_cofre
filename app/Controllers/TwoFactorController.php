<?php
// app/Controllers/TwoFactorController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthService;
use App\Services\TwoFactorService;

/** Segunda etapa do login (código TOTP ou código de recuperação). */
final class TwoFactorController extends Controller
{
    public function challenge(): Response
    {
        $user = Auth::twoFactorPendingUser();
        if ($user === null) {
            $this->flash('warning', 'Sua verificação expirou. Entre novamente.');
            return $this->redirectRoute('auth.login');
        }
        return $this->view('auth/two-factor', ['title' => 'Verificação em duas etapas', 'name' => $user['name']]);
    }

    public function verify(): Response
    {
        $user = Auth::twoFactorPendingUser();
        if ($user === null) {
            $this->flash('warning', 'Sua verificação expirou. Entre novamente.');
            return $this->redirectRoute('auth.login');
        }
        $status = RateLimiter::status('totp', $this->request->ip(), (string) $user['email']);
        if ($status['blocked_seconds'] > 0) {
            Session::flashErrors(['code' => ['Muitas tentativas. Aguarde ' . RateLimiter::humanWait($status['blocked_seconds']) . '.']]);
            return $this->redirectRoute('auth.two_factor');
        }
        $data = $this->validate(['code' => 'required|min:6|max:20'], ['code' => 'código'], 'auth.two_factor');
        $code = (string) $data['code'];

        $ok = TwoFactorService::verifyCode($user, $code);
        if (!$ok && str_contains($code, '-')) {
            $ok = TwoFactorService::verifyRecoveryCode($user, $code);
            if ($ok) {
                $this->flash('warning', 'Você entrou com um código de recuperação. Restam ' . TwoFactorService::remainingRecoveryCodes((new \App\Models\User())->find((int) $user['id']) ?? []) . '. Gere novos em Conta → Verificação em duas etapas.');
            }
        }
        if (!$ok) {
            RateLimiter::record('totp', $this->request->ip(), (string) $user['email'], false, $this->request->userAgent());
            Logger::security('Código 2FA inválido', ['user_id' => $user['id'], 'ip' => $this->request->ip()]);
            Session::flashErrors(['code' => ['Código inválido. Confira o relógio do celular e tente de novo.']]);
            return $this->redirectRoute('auth.two_factor');
        }
        RateLimiter::clear('totp', (string) $user['email']);
        $remember = (bool) Session::get('2fa_remember', false);
        Session::forget('2fa_remember');
        AuthService::finalizeLogin($user, $remember, $this->request);
        return (new AuthController($this->request))->afterLogin();
    }
}
