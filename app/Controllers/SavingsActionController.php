<?php
// app/Controllers/SavingsActionController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\Category;
use App\Models\HouseholdMember;
use App\Models\SavingsAction;
use App\Services\AuditService;
use App\Services\SavingsActionService;

/** Plano de ação de economia (/plano). */
final class SavingsActionController extends Controller
{
    public function index(): Response
    {
        $householdId = (int) Auth::householdId();
        $today = new \DateTimeImmutable('today', user_timezone());
        return $this->view('savings/index', [
            'title'      => 'Plano de ação',
            'plan'       => SavingsActionService::overview($householdId, $today),
            'categories' => (new Category())->tree('expense'),
            'categoryMap'=> (new Category())->map(),
            'members'    => (new HouseholdMember())->activeMembers(),
            'canWrite'   => Auth::canWrite(),
            'isFamily'   => Auth::isFamily(),
            'statuses'   => SavingsAction::STATUSES,
        ]);
    }

    public function store(): Response
    {
        $this->requireWrite();
        $data = $this->validated();
        $data['status'] = 'todo';
        $data['sort_order'] = (new SavingsAction())->count() + 1;
        $id = (new SavingsAction())->create($data);
        AuditService::log('savings_action.created', 'savings_action', $id, null, ['title' => $data['title'], 'estimated' => $data['estimated_saving_month']]);
        $this->flash('success', 'Ação adicionada ao plano.');
        return $this->redirectRoute('savings.index');
    }

    public function update(string $id): Response
    {
        $this->requireWrite();
        $model = new SavingsAction();
        $before = $model->findOrFail((int) $id);
        $data = $this->validated();
        $model->update((int) $id, $data);
        AuditService::log('savings_action.updated', 'savings_action', (int) $id, array_intersect_key($before, $data), $data);
        $this->flash('success', 'Ação atualizada.');
        return $this->redirectRoute('savings.index');
    }

    public function status(string $id): Response
    {
        $this->requireWrite();
        $data = $this->validate(['status' => 'required|in:todo,doing,done'], ['status' => 'situação'], 'savings.index');
        SavingsActionService::setStatus((int) Auth::householdId(), (int) $id, (string) $data['status'], new \DateTimeImmutable('today', user_timezone()));
        $this->flash('success', match ($data['status']) { 'doing' => 'Ação iniciada: a linha de base (média dos 3 meses anteriores) foi registrada.', 'done' => 'Ação concluída. 👏', default => 'Ação voltou para "a fazer".' });
        return $this->redirectRoute('savings.index');
    }

    public function destroy(string $id): Response
    {
        $this->requireWrite();
        $model = new SavingsAction();
        $action = $model->findOrFail((int) $id);
        $model->delete((int) $id);
        AuditService::log('savings_action.deleted', 'savings_action', (int) $id, ['title' => $action['title']], null);
        $this->flash('success', 'Ação removida.');
        return $this->redirectRoute('savings.index');
    }

    /** @return array<string,mixed> */
    private function validated(): array
    {
        $data = $this->validate([
            'title'                  => 'required|min:3|max:190',
            'description'            => 'nullable|max:1000',
            'responsible_user_id'    => 'nullable|integer',
            'category_id'            => 'nullable|integer',
            'estimated_saving_month' => 'nullable|money',
        ], ['title' => 'título', 'description' => 'descrição', 'responsible_user_id' => 'responsável', 'category_id' => 'categoria', 'estimated_saving_month' => 'economia estimada'], 'savings.index');
        $householdId = (int) Auth::householdId();
        if (!empty($data['responsible_user_id']) && (int) Database::scalar('SELECT COUNT(*) FROM household_members WHERE household_id = ? AND user_id = ? AND left_at IS NULL', [$householdId, (int) $data['responsible_user_id']]) === 0) {
            throw new HttpException(422, 'Responsável inválido.');
        }
        if (!empty($data['category_id']) && !(new Category())->validFor((int) $data['category_id'], 'expense')) {
            throw new HttpException(422, 'Categoria inválida.');
        }
        return [
            'title'                  => $data['title'],
            'description'            => $data['description'] ?: null,
            'responsible_user_id'    => !empty($data['responsible_user_id']) ? (int) $data['responsible_user_id'] : null,
            'category_id'            => !empty($data['category_id']) ? (int) $data['category_id'] : null,
            'estimated_saving_month' => $data['estimated_saving_month'] ?? '0.00',
        ];
    }

    private function requireWrite(): void
    {
        if (!Auth::canWrite()) {
            throw new HttpException(403, 'Seu papel no lar é somente leitura.');
        }
    }
}
