<?php
// app/Controllers/EmailVerificationController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Core\Session;
use App\Models\User;
use App\Services\AuthService;

final class EmailVerificationController extends Controller
{
    public function notice(): Response
    {
        return $this->view('auth/verify-notice', [
            'title' => 'Confirme seu e-mail',
            'email' => (string) Session::get('pending_verification_email', ''),
        ]);
    }

    public function verify(string $token): Response
    {
        $user = AuthService::verifyEmail($token);
        if ($user === null) {
            $this->flash('danger', 'Este link de confirmação é inválido ou já expirou. Peça um novo abaixo.');
            return $this->redirectRoute('verification.notice');
        }
        Session::forget('pending_verification_email');
        if (Auth::check() && Auth::id() === (int) $user['id']) {
            Auth::refresh();
            $this->flash('success', 'E-mail confirmado!');
            return $this->redirectRoute('dashboard');
        }
        $this->flash('success', 'E-mail confirmado! Agora é só entrar.');
        return $this->redirectRoute('auth.login');
    }

    public function resend(): Response
    {
        $data = $this->validate(['email' => 'required|email'], ['email' => 'e-mail'], 'verification.notice');
        $recent = (int) Database::scalar(
            'SELECT COUNT(*) FROM email_verifications ev JOIN users u ON u.id = ev.user_id WHERE u.email = ? AND ev.created_at >= ?',
            [$data['email'], gmdate('Y-m-d H:i:s', time() - 900)]
        );
        $user = (new User())->findByEmail($data['email']);
        if ($user !== null && empty($user['email_verified_at']) && $recent < 3) {
            AuthService::sendVerification($user);
        }
        RateLimiter::record('register', $this->request->ip(), $data['email'], true, $this->request->userAgent());
        Session::set('pending_verification_email', $data['email']);
        // Mensagem idêntica exista ou não a conta (não revela e-mails cadastrados)
        $this->flash('info', 'Se houver uma conta pendente de confirmação para ' . $data['email'] . ', um novo link foi enviado.');
        return $this->redirectRoute('verification.notice');
    }
}
