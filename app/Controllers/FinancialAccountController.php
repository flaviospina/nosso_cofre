<?php
// app/Controllers/FinancialAccountController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\Account;
use App\Models\HouseholdMember;
use App\Services\AccountService;
use App\Services\AuditService;

/** Contas e cartões (/contas). */
final class FinancialAccountController extends Controller
{
    public function index(): Response
    {
        $accounts = AccountService::withBalances((int) Auth::householdId(), false);
        return $this->view('accounts/index', [
            'title'    => 'Contas e cartões',
            'accounts' => $accounts,
            'totals'   => AccountService::totals(array_filter($accounts, static fn(array $a): bool => (int) $a['is_active'] === 1)),
            'canWrite' => Auth::canWrite(),
            'members'  => $this->membersMap(),
        ]);
    }

    public function create(): Response
    {
        $this->requireWrite();
        return $this->view('accounts/form', ['title' => 'Nova conta', 'account' => null, 'members' => (new HouseholdMember())->activeMembers(), 'types' => Account::TYPES]);
    }

    public function store(): Response
    {
        $this->requireWrite();
        $data = $this->validated('accounts.create');
        $data['icon'] = Account::ICONS[$data['type']] ?? 'bank';
        $data['sort_order'] = (new Account())->count() + 1;
        $id = (new Account())->create($data);
        AuditService::log('account.created', 'account', $id, null, ['name' => $data['name'], 'type' => $data['type']]);
        $this->flash('success', 'Conta "' . $data['name'] . '" criada.');
        return $this->redirectRoute('accounts.index');
    }

    public function edit(string $id): Response
    {
        $this->requireWrite();
        $account = (new Account())->findOrFail((int) $id);
        return $this->view('accounts/form', ['title' => 'Editar conta', 'account' => $account, 'members' => (new HouseholdMember())->activeMembers(), 'types' => Account::TYPES]);
    }

    public function update(string $id): Response
    {
        $this->requireWrite();
        $model = new Account();
        $before = $model->findOrFail((int) $id);
        $data = $this->validated('accounts.edit', (int) $id);
        $data['icon'] = Account::ICONS[$data['type']] ?? 'bank';
        $model->update((int) $id, $data);
        AuditService::log('account.updated', 'account', (int) $id, array_intersect_key($before, $data), $data);
        $this->flash('success', 'Conta atualizada.');
        return $this->redirectRoute('accounts.index');
    }

    /** Arquiva/reativa (contas com lançamentos não são excluídas, só arquivadas). */
    public function toggle(string $id): Response
    {
        $this->requireWrite();
        $model = new Account();
        $account = $model->findOrFail((int) $id);
        $active = (int) $account['is_active'] === 1 ? 0 : 1;
        $model->update((int) $id, ['is_active' => $active]);
        AuditService::log($active ? 'account.reactivated' : 'account.archived', 'account', (int) $id);
        $this->flash('success', $active ? 'Conta reativada.' : 'Conta arquivada. Os lançamentos continuam nos relatórios.');
        return $this->redirectRoute('accounts.index');
    }

    public function destroy(string $id): Response
    {
        $this->requireWrite();
        $model = new Account();
        $account = $model->findOrFail((int) $id);
        $used = (int) Database::scalar('SELECT COUNT(*) FROM transactions WHERE household_id = ? AND (account_id = ? OR transfer_account_id = ?)', [(int) Auth::householdId(), (int) $id, (int) $id]);
        if ($used > 0) {
            $this->flash('danger', 'Esta conta tem lançamentos. Arquive-a em vez de excluir.');
            return $this->redirectRoute('accounts.index');
        }
        $model->delete((int) $id);
        AuditService::log('account.deleted', 'account', (int) $id, ['name' => $account['name']], null);
        $this->flash('success', 'Conta excluída.');
        return $this->redirectRoute('accounts.index');
    }

    /** @return array<string,mixed> */
    private function validated(string $backRoute, ?int $ignoreId = null): array
    {
        $data = $this->validate([
            'name'            => 'required|min:2|max:80',
            'type'            => 'required|in:checking,savings,credit_card,cash,investment',
            'owner_user_id'   => 'nullable|integer',
            'institution'     => 'nullable|max:80',
            'initial_balance' => 'nullable|money',
            'closing_day'     => 'nullable|integer|between:1,31',
            'due_day'         => 'nullable|integer|between:1,31',
            'limit_amount'    => 'nullable|money',
            'color'           => 'nullable|color',
        ], ['name' => 'nome', 'type' => 'tipo', 'owner_user_id' => 'dono', 'institution' => 'instituição', 'initial_balance' => 'saldo inicial', 'closing_day' => 'dia de fechamento', 'due_day' => 'dia de vencimento', 'limit_amount' => 'limite'], $backRoute);
        if ($data['owner_user_id'] !== null && $data['owner_user_id'] !== 0) {
            $isMember = Database::scalar('SELECT COUNT(*) FROM household_members WHERE household_id = ? AND user_id = ? AND left_at IS NULL', [(int) Auth::householdId(), (int) $data['owner_user_id']]);
            if ((int) $isMember === 0) {
                throw new HttpException(422, 'Dono inválido.');
            }
        } else {
            $data['owner_user_id'] = null;
        }
        if ($data['type'] !== 'credit_card') {
            $data['closing_day'] = null;
            $data['due_day'] = null;
            $data['limit_amount'] = null;
        }
        $data['initial_balance'] = $data['initial_balance'] ?? '0.00';
        return $data;
    }

    /** @return array<int,string> */
    private function membersMap(): array
    {
        $map = [];
        foreach ((new HouseholdMember())->activeMembers() as $m) {
            $map[(int) $m['user_id']] = (string) $m['name'];
        }
        return $map;
    }

    private function requireWrite(): void
    {
        if (!Auth::canWrite()) {
            throw new HttpException(403, 'Seu papel no lar é somente leitura.');
        }
    }
}
