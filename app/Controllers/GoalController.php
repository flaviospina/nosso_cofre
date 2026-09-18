<?php
// app/Controllers/GoalController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\Goal;
use App\Models\HouseholdMember;
use App\Services\AccountService;
use App\Services\AuditService;
use App\Services\GoalService;

/** Metas (/metas). */
final class GoalController extends Controller
{
    public function index(): Response
    {
        $householdId = (int) Auth::householdId();
        $today = new \DateTimeImmutable('today', user_timezone());
        $goals = GoalService::withProgress($householdId, $today);
        $contributions = [];
        foreach ($goals as $g) {
            $contributions[(int) $g['id']] = GoalService::contributions($householdId, (int) $g['id'], 8);
        }
        return $this->view('goals/index', [
            'title'         => 'Metas',
            'goals'         => $goals,
            'contributions' => $contributions,
            'accounts'      => array_values(array_filter(AccountService::withBalances($householdId, false), static fn(array $a): bool => (int) $a['is_active'] === 1)),
            'members'       => (new HouseholdMember())->activeMembers(),
            'canWrite'      => Auth::canWrite(),
            'isFamily'      => Auth::isFamily(),
            'today'         => $today->format('Y-m-d'),
        ]);
    }

    public function store(): Response
    {
        $this->requireWrite();
        $data = $this->validated();
        $data['saved_amount'] = $data['saved_amount'] ?? '0.00';
        $data['status'] = 'active';
        $id = (new Goal())->create($data);
        AuditService::log('goal.created', 'goal', $id, null, ['name' => $data['name'], 'target' => $data['target_amount']]);
        $this->flash('success', 'Meta "' . $data['name'] . '" criada.');
        return $this->redirectRoute('goals.index');
    }

    public function update(string $id): Response
    {
        $this->requireWrite();
        $model = new Goal();
        $before = $model->findOrFail((int) $id);
        $data = $this->validated();
        unset($data['saved_amount']);
        $model->update((int) $id, $data);
        AuditService::log('goal.updated', 'goal', (int) $id, array_intersect_key($before, $data), $data);
        $this->flash('success', 'Meta atualizada.');
        return $this->redirectRoute('goals.index');
    }

    public function contribute(string $id): Response
    {
        $this->requireWrite();
        $data = $this->validate(['amount' => 'required|money', 'date' => 'required|date', 'note' => 'nullable|max:190', 'withdraw' => 'nullable|boolean'], ['amount' => 'valor', 'date' => 'data', 'note' => 'observação'], 'goals.index');
        $amount = abs((float) $data['amount']) * (!empty($data['withdraw']) ? -1 : 1);
        if ($amount == 0) {
            $this->flash('danger', 'Informe um valor maior que zero.');
            return $this->redirectRoute('goals.index');
        }
        $result = GoalService::contribute((int) Auth::householdId(), (int) $id, $amount, (string) $data['date'], $data['note'] ?: null, Auth::id());
        $this->flash('success', $result['achieved'] ? '🎉 Meta batida! Parabéns.' : ($amount > 0 ? 'Aporte registrado.' : 'Retirada registrada.'));
        return $this->redirectRoute('goals.index');
    }

    /** Arquiva / reativa. */
    public function toggle(string $id): Response
    {
        $this->requireWrite();
        $model = new Goal();
        $goal = $model->findOrFail((int) $id);
        $status = $goal['status'] === 'archived' ? 'active' : 'archived';
        $model->update((int) $id, ['status' => $status]);
        AuditService::log('goal.updated', 'goal', (int) $id, ['status' => $goal['status']], ['status' => $status]);
        $this->flash('success', $status === 'archived' ? 'Meta arquivada.' : 'Meta reativada.');
        return $this->redirectRoute('goals.index');
    }

    public function destroy(string $id): Response
    {
        $this->requireWrite();
        $model = new Goal();
        $goal = $model->findOrFail((int) $id);
        $model->delete((int) $id);
        AuditService::log('goal.deleted', 'goal', (int) $id, ['name' => $goal['name']], null);
        $this->flash('success', 'Meta excluída.');
        return $this->redirectRoute('goals.index');
    }

    /** @return array<string,mixed> */
    private function validated(): array
    {
        $data = $this->validate([
            'name'              => 'required|min:2|max:120',
            'target_amount'     => 'required|money',
            'saved_amount'      => 'nullable|money',
            'deadline'          => 'nullable|date',
            'linked_account_id' => 'nullable|integer',
            'user_id'           => 'nullable|integer',
            'color'             => 'nullable|color',
            'icon'              => 'nullable|alpha_dash|max:40',
        ], ['name' => 'nome', 'target_amount' => 'valor da meta', 'saved_amount' => 'já guardado', 'deadline' => 'prazo', 'linked_account_id' => 'conta ligada', 'user_id' => 'membro', 'color' => 'cor', 'icon' => 'ícone'], 'goals.index');
        if ((float) $data['target_amount'] <= 0) {
            throw new HttpException(422, 'O valor da meta deve ser maior que zero.');
        }
        $householdId = (int) Auth::householdId();
        if (!empty($data['linked_account_id']) && (int) Database::scalar('SELECT COUNT(*) FROM accounts WHERE id = ? AND household_id = ? AND deleted_at IS NULL', [(int) $data['linked_account_id'], $householdId]) === 0) {
            throw new HttpException(422, 'Conta inválida.');
        }
        if (!empty($data['user_id']) && (int) Database::scalar('SELECT COUNT(*) FROM household_members WHERE household_id = ? AND user_id = ? AND left_at IS NULL', [$householdId, (int) $data['user_id']]) === 0) {
            throw new HttpException(422, 'Membro inválido.');
        }
        return [
            'name'              => $data['name'],
            'target_amount'     => $data['target_amount'],
            'saved_amount'      => $data['saved_amount'] ?? null,
            'deadline'          => $data['deadline'] ?: null,
            'linked_account_id' => !empty($data['linked_account_id']) ? (int) $data['linked_account_id'] : null,
            'user_id'           => !empty($data['user_id']) ? (int) $data['user_id'] : null,
            'color'             => $data['color'] ?: '#0f766e',
            'icon'              => $data['icon'] ?: 'flag',
        ];
    }

    private function requireWrite(): void
    {
        if (!Auth::canWrite()) {
            throw new HttpException(403, 'Seu papel no lar é somente leitura.');
        }
    }
}
