<?php
// app/Controllers/PasswordResetController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Services\AuthService;

final class PasswordResetController extends Controller
{
    public function requestForm(): Response
    {
        return $this->view('auth/forgot', ['title' => 'Recuperar senha']);
    }

    public function sendLink(): Response
    {
        $data = $this->validate(['email' => 'required|email'], ['email' => 'e-mail'], 'password.request');
        $status = RateLimiter::status('reset', $this->request->ip(), $data['email']);
        // Limite por IP/e-mail: acima de 5 pedidos por hora, apenas finge (mesma resposta)
        $recent = (int) \App\Core\Database::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE kind = ? AND created_at >= ? AND (ip = ? OR email = ?)',
            ['reset', gmdate('Y-m-d H:i:s', time() - 3600), $this->request->ip(), $data['email']]
        );
        if ($recent < 5 && $status['blocked_seconds'] === 0) {
            AuthService::requestPasswordReset($data['email'], $this->request);
        }
        $this->flash('info', 'Se ' . $data['email'] . ' estiver cadastrado, enviamos um link para redefinir a senha. Ele vale por 1 hora.');
        return $this->redirectRoute('auth.login');
    }

    public function resetForm(string $token): Response
    {
        $user = AuthService::findResetUser($token);
        if ($user === null) {
            $this->flash('danger', 'Este link de redefinição é inválido ou já expirou. Peça um novo.');
            return $this->redirectRoute('password.request');
        }
        return $this->view('auth/reset', ['title' => 'Nova senha', 'token' => $token, 'email' => $user['email']]);
    }

    public function update(): Response
    {
        $data = $this->validate([
            'token'    => 'required|max:100',
            'password' => 'required|password|confirmed',
        ], ['password' => 'senha']);
        if (!AuthService::resetPassword((string) $data['token'], (string) $data['password'])) {
            $this->flash('danger', 'Este link de redefinição é inválido ou já expirou. Peça um novo.');
            return $this->redirectRoute('password.request');
        }
        $this->flash('success', 'Senha redefinida. Por segurança, todas as sessões anteriores foram encerradas. Entre com a nova senha.');
        return $this->redirectRoute('auth.login');
    }
}
