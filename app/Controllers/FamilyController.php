<?php
// app/Controllers/FamilyController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Models\HouseholdMember;
use App\Models\Invitation;
use App\Services\HouseholdService;
use App\Services\InvitationService;

/** Lar: membros, papéis, convites, configurações e conversão individual → familiar. */
final class FamilyController extends Controller
{
    public function index(): Response
    {
        $household = Auth::household();
        $members = (new HouseholdMember())->activeMembers();
        $invitations = Auth::isFamily() && Auth::canManage() ? (new Invitation())->pending() : [];
        return $this->view('family/index', [
            'title'       => Auth::isFamily() ? 'Família' : 'Meu lar',
            'household'   => $household,
            'members'     => $members,
            'invitations' => $invitations,
            'canManage'   => Auth::canManage(),
            'isOwner'     => Auth::isOwner(),
            'roleLabels'  => Auth::ROLE_LABELS,
            'me'          => Auth::id(),
        ]);
    }

    public function convert(): Response
    {
        $this->requireOwner();
        $data = $this->validate(['name' => 'required|min:2|max:120'], ['name' => 'nome do lar'], 'family.index');
        HouseholdService::convertToFamily((int) Auth::householdId(), (string) $data['name']);
        $this->flash('success', 'Sua conta agora é familiar. Convide os membros abaixo. Como responsável, você precisará ativar a verificação em duas etapas.');
        return $this->redirectRoute('family.index');
    }

    public function updateSettings(): Response
    {
        $this->requireManager();
        $data = $this->validate([
            'name'                    => 'required|min:2|max:120',
            'members_can_edit_others' => 'nullable|boolean',
        ], ['name' => 'nome do lar'], 'family.index');
        HouseholdService::updateSettings((int) Auth::householdId(), (string) $data['name'], [
            'members_can_edit_others' => (bool) ($data['members_can_edit_others'] ?? false),
        ]);
        $this->flash('success', 'Configurações do lar salvas.');
        return $this->redirectRoute('family.index');
    }

    public function invite(): Response
    {
        $this->requireManager();
        if (!Auth::isFamily()) {
            throw new HttpException(403, 'Converta sua conta para familiar antes de convidar membros.');
        }
        $data = $this->validate(['email' => 'required|email', 'role' => 'required|in:admin,member,viewer'], ['email' => 'e-mail', 'role' => 'papel'], 'family.index');
        if ($data['role'] === 'admin' && !Auth::isOwner()) {
            throw new HttpException(403, 'Só o responsável pode convidar administradores.');
        }
        $error = InvitationService::invite((int) Auth::householdId(), (string) $data['email'], (string) $data['role'], (int) Auth::id());
        if ($error !== null) {
            Session::flashErrors(['email' => [$error]]);
            Session::flashInput($data);
        } else {
            $this->flash('success', 'Convite enviado para ' . $data['email'] . '. Vale por 7 dias.');
        }
        return $this->redirectRoute('family.index');
    }

    public function resendInvitation(string $id): Response
    {
        $this->requireManager();
        $error = InvitationService::resend((int) Auth::householdId(), (int) $id, (int) Auth::id());
        $this->flash($error === null ? 'success' : 'danger', $error ?? 'Convite reenviado.');
        return $this->redirectRoute('family.index');
    }

    public function revokeInvitation(string $id): Response
    {
        $this->requireManager();
        InvitationService::revoke((int) Auth::householdId(), (int) $id, (int) Auth::id());
        $this->flash('success', 'Convite cancelado.');
        return $this->redirectRoute('family.index');
    }

    public function changeRole(string $id): Response
    {
        $this->requireManager();
        $data = $this->validate(['role' => 'required|in:admin,member,viewer'], ['role' => 'papel'], 'family.index');
        if ($data['role'] === 'admin' && !Auth::isOwner()) {
            throw new HttpException(403, 'Só o responsável pode promover a administrador.');
        }
        $target = \App\Core\Database::selectOne('SELECT role FROM household_members WHERE id = ? AND household_id = ?', [(int) $id, (int) Auth::householdId()]);
        if ($target !== null && $target['role'] === 'admin' && !Auth::isOwner()) {
            throw new HttpException(403, 'Só o responsável pode alterar um administrador.');
        }
        $ok = HouseholdService::changeRole((int) Auth::householdId(), (int) $id, (string) $data['role'], (int) Auth::id());
        $this->flash($ok ? 'success' : 'danger', $ok ? 'Papel atualizado.' : 'Não foi possível alterar esse membro.');
        return $this->redirectRoute('family.index');
    }

    public function removeMember(string $id): Response
    {
        $this->requireManager();
        $target = \App\Core\Database::selectOne('SELECT role, user_id FROM household_members WHERE id = ? AND household_id = ?', [(int) $id, (int) Auth::householdId()]);
        if ($target !== null && $target['role'] === 'admin' && !Auth::isOwner()) {
            throw new HttpException(403, 'Só o responsável pode remover um administrador.');
        }
        $ok = HouseholdService::removeMember((int) Auth::householdId(), (int) $id, (int) Auth::id());
        $this->flash($ok ? 'success' : 'danger', $ok ? 'Membro removido. Os lançamentos dele continuam no lar.' : 'Não foi possível remover esse membro.');
        return $this->redirectRoute('family.index');
    }

    /** O próprio membro sai do lar (o responsável não pode sair; precisa transferir ou excluir o lar). */
    public function leave(): Response
    {
        if (Auth::isOwner()) {
            throw new HttpException(403, 'O responsável não pode sair do lar. A exclusão do lar entra na área de privacidade.');
        }
        $member = Auth::member();
        if ($member !== null) {
            HouseholdService::removeMember((int) Auth::householdId(), (int) $member['id'], (int) Auth::id(), true);
        }
        Session::set('household_id', null);
        Auth::refresh();
        $this->flash('info', 'Você saiu do lar.');
        return $this->redirectRoute('onboarding.start');
    }

    private function requireManager(): void
    {
        if (!Auth::canManage()) {
            throw new HttpException(403, 'Só o responsável ou um administrador pode fazer isso.');
        }
    }

    private function requireOwner(): void
    {
        if (!Auth::isOwner()) {
            throw new HttpException(403, 'Só o responsável pelo lar pode fazer isso.');
        }
    }
}
