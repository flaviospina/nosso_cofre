<?php
// app/Controllers/AuthController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Captcha;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\LegalService;

final class AuthController extends Controller
{
    // --- Cadastro ---

    public function registerForm(): Response
    {
        return $this->view('auth/register', [
            'title'          => 'Criar conta',
            'termsVersion'   => LegalService::version('terms'),
            'privacyVersion' => LegalService::version('privacy'),
            'invitedEmail'   => (string) Session::get('invite_email', ''),
        ]);
    }

    public function register(): Response
    {
        $status = RateLimiter::status('register', $this->request->ip(), null);
        $recent = (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE kind = ? AND ip = ? AND succeeded = 1 AND created_at >= ?',
            ['register', $this->request->ip(), gmdate('Y-m-d H:i:s', time() - 3600)]
        );
        if ($recent >= 5 || $status['blocked_seconds'] > 0) {
            throw new HttpException(429, 'Muitos cadastros a partir deste endereço. Tente novamente mais tarde.');
        }
        $data = $this->validate([
            'name'     => 'required|min:2|max:120',
            'email'    => 'required|email',
            'password' => 'required|password|confirmed',
            'terms'    => 'accepted',
            'adult'    => 'accepted',
        ], [
            'name' => 'nome', 'email' => 'e-mail', 'password' => 'senha',
            'terms' => 'os Termos de Uso e a Política de Privacidade', 'adult' => 'a confirmação de maioridade',
        ], 'auth.register');

        $email = mb_strtolower(trim((string) $data['email']));
        $existing = (new \App\Models\User())->findByEmail($email);
        if ($existing !== null) {
            // Não revela que o e-mail já tem conta: a resposta é a mesma e quem é dono do endereço recebe um aviso
            AuthService::notifyExistingAccount($existing, $this->request);
        } else {
            AuthService::register([
                'name'            => $data['name'],
                'email'           => $email,
                'password'        => $data['password'],
                'terms_version'   => LegalService::version('terms'),
                'privacy_version' => LegalService::version('privacy'),
            ], $this->request);
        }

        Session::set('pending_verification_email', $email);
        $this->flash('success', 'Conta criada! Enviamos um link de confirmação para ' . $email . '. Ele vale por 24 horas.');
        return $this->redirectRoute('verification.notice');
    }

    // --- Login ---

    public function loginForm(): Response
    {
        $email = mb_strtolower(trim((string) old('email', '')));
        $status = RateLimiter::status('login', $this->request->ip(), $email !== '' ? $email : null);
        return $this->view('auth/login', [
            'title'   => 'Entrar',
            'captcha' => $status['captcha'] ? Captcha::challenge() : null,
        ]);
    }

    public function login(): Response
    {
        $data = $this->validate([
            'email'    => 'required|email',
            'password' => 'required',
            'remember' => 'nullable|boolean',
        ], ['email' => 'e-mail', 'password' => 'senha'], 'auth.login');

        $result = AuthService::attemptLogin(
            $data['email'],
            $data['password'],
            $this->request,
            $this->request->str('captcha_token') ?: null,
            $this->request->str('captcha_answer') ?: null
        );

        if (!$result['ok']) {
            $message = match ($result['reason']) {
                'blocked'    => 'Muitas tentativas. Aguarde ' . RateLimiter::humanWait((int) $result['wait']) . ' e tente de novo.',
                'captcha'    => 'Resolva a conta de verificação para continuar.',
                'unverified' => 'Você ainda não confirmou seu e-mail. Verifique sua caixa de entrada ou peça um novo link abaixo.',
                default      => 'E-mail ou senha incorretos.',
            };
            if ($result['reason'] === 'unverified') {
                Session::set('pending_verification_email', $data['email']);
                $this->flash('warning', $message);
                return $this->redirectRoute('verification.notice');
            }
            if ($this->request->wantsJson()) {
                return $this->json(false, ['reason' => $result['reason']], $message, $result['reason'] === 'blocked' ? 429 : 422);
            }
            Session::flashErrors(['email' => [$message]]);
            Session::flashInput(['email' => $data['email']]);
            return $this->redirectRoute('auth.login');
        }

        $remember = (bool) ($data['remember'] ?? false);
        if ($result['two_factor']) {
            Session::set('2fa_remember', $remember);
            return $this->redirectRoute('auth.two_factor');
        }
        AuthService::finalizeLogin($result['user'], $remember, $this->request);
        return $this->afterLogin();
    }

    /** Destino após login: URL pretendida, onboarding ou painel. */
    public function afterLogin(): Response
    {
        $intended = (string) Session::get('_intended', '');
        Session::forget('_intended');
        if (Auth::householdId() === null) {
            return $this->redirectRoute('onboarding.start');
        }
        if ($intended !== '' && str_starts_with($intended, '/') && !str_starts_with($intended, '//')) {
            return $this->redirect(url($intended));
        }
        return $this->redirectRoute('dashboard');
    }

    public function logout(): Response
    {
        $userId = Auth::id();
        if ($userId !== null) {
            AuditService::log('user.logout', 'user', $userId);
        }
        Auth::logout();
        $this->flash('info', 'Você saiu. Até logo!');
        return $this->redirectRoute('home');
    }
}
