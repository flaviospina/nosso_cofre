<?php
// app/Controllers/InvitationController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Models\Invitation;
use App\Services\InvitationService;

/** Aceite de convite por link. */
final class InvitationController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = Invitation::findValidByToken($token);
        if ($invitation === null) {
            return $this->view('invitation/invalid', ['title' => 'Convite inválido']);
        }
        Session::set('invite_email', $invitation['email']);
        Session::set('invite_token', $token);
        $user = Auth::user();
        return $this->view('invitation/show', [
            'title'      => 'Convite para ' . $invitation['household_name'],
            'invitation' => $invitation,
            'token'      => $token,
            'user'       => $user,
            'emailMatches' => $user !== null && mb_strtolower((string) $user['email']) === mb_strtolower((string) $invitation['email']),
        ]);
    }

    public function accept(string $token): Response
    {
        $user = Auth::user();
        if ($user === null) {
            Session::set('_intended', '/convite/' . $token);
            $this->flash('info', 'Entre ou crie sua conta para aceitar o convite.');
            return $this->redirectRoute('auth.login');
        }
        $invitation = Invitation::findValidByToken($token);
        if ($invitation === null) {
            return $this->view('invitation/invalid', ['title' => 'Convite inválido']);
        }
        if (mb_strtolower((string) $user['email']) !== mb_strtolower((string) $invitation['email'])) {
            $this->flash('danger', 'Este convite foi enviado para ' . $invitation['email'] . ', que não é o e-mail da sua conta.');
            return $this->redirectRoute('dashboard');
        }
        InvitationService::accept($invitation, (int) $user['id']);
        Session::forget('invite_email');
        Session::forget('invite_token');
        Auth::switchHousehold((int) $invitation['household_id']);
        $this->flash('success', 'Você agora faz parte de "' . $invitation['household_name'] . '".');
        return $this->redirectRoute('dashboard');
    }
}
