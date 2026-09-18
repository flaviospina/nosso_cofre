<?php
// app/Controllers/BudgetController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\Budget;
use App\Models\Category;
use App\Models\HouseholdMember;
use App\Services\AuditService;
use App\Services\BudgetService;

/** Orçamento mensal (/orcamento). */
final class BudgetController extends Controller
{
    public function index(): Response
    {
        $householdId = (int) Auth::householdId();
        $today = new \DateTimeImmutable('today', user_timezone());
        $month = $this->month();
        $averages = [];
        foreach (Database::select(
            "SELECT t.category_id, c.parent_id, SUM(t.amount) / 3 AS monthly FROM transactions t JOIN categories c ON c.id = t.category_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.type = 'expense' AND t.status <> 'scheduled' AND t.date >= ? AND t.date < ?
              GROUP BY t.category_id, c.parent_id",
            [$householdId, (new \DateTimeImmutable($month))->modify('-3 months')->format('Y-m-d'), $month]
        ) as $r) {
            $averages[(int) $r['category_id']] = round((float) $r['monthly'], 2);
            if ($r['parent_id'] !== null) {
                $averages[(int) $r['parent_id']] = round(($averages[(int) $r['parent_id']] ?? 0) + (float) $r['monthly'], 2);
            }
        }
        return $this->view('budgets/index', [
            'title'      => 'Orçamento',
            'month'      => $month,
            'monthLabel' => month_name((int) substr($month, 5, 2)) . ' de ' . substr($month, 0, 4),
            'prevMonth'  => (new \DateTimeImmutable($month))->modify('-1 month')->format('Y-m'),
            'nextMonth'  => (new \DateTimeImmutable($month))->modify('+1 month')->format('Y-m'),
            'budgets'    => BudgetService::overview($householdId, $month, $today),
            'split'      => BudgetService::essentialSplit($householdId, $month),
            'categories' => (new Category())->tree('expense'),
            'averages'   => $averages,
            'members'    => (new HouseholdMember())->activeMembers(),
            'hasPrevious'=> (int) Database::scalar('SELECT COUNT(*) FROM budgets WHERE household_id = ? AND period_month = ?', [$householdId, (new \DateTimeImmutable($month))->modify('-1 month')->format('Y-m-01')]) > 0,
            'canWrite'   => Auth::canWrite(),
            'isFamily'   => Auth::isFamily(),
            'isCurrent'  => $today->format('Y-m') === substr($month, 0, 7),
        ]);
    }

    public function store(): Response
    {
        $this->requireWrite();
        $data = $this->validate([
            'category_id'  => 'required|integer',
            'user_id'      => 'nullable|integer',
            'limit_amount' => 'required|money',
            'warn_at'      => 'nullable|integer|between:1,100',
            'mes'          => 'nullable|regex:/^\d{4}-\d{2}$/',
        ], ['category_id' => 'categoria', 'user_id' => 'membro', 'limit_amount' => 'limite', 'warn_at' => 'aviso em'], 'budgets.index');
        $householdId = (int) Auth::householdId();
        $month = $this->month((string) ($data['mes'] ?? ''));
        if (!(new Category())->validFor((int) $data['category_id'], 'expense')) {
            throw new HttpException(422, 'Categoria inválida.');
        }
        if ((float) $data['limit_amount'] <= 0) {
            $this->flash('danger', 'O limite deve ser maior que zero.');
            return $this->redirectRoute('budgets.index', [], ['mes' => substr($month, 0, 7)]);
        }
        $userId = !empty($data['user_id']) ? (int) $data['user_id'] : null;
        if ($userId !== null && (int) Database::scalar('SELECT COUNT(*) FROM household_members WHERE household_id = ? AND user_id = ? AND left_at IS NULL', [$householdId, $userId]) === 0) {
            throw new HttpException(422, 'Membro inválido.');
        }
        $thresholds = [(int) ($data['warn_at'] ?: 80), 100];
        $existing = Database::scalar('SELECT id FROM budgets WHERE household_id = ? AND period_month = ? AND category_id = ? AND (user_id <=> ?)', [$householdId, $month, (int) $data['category_id'], $userId]);
        $model = new Budget();
        if ($existing !== null) {
            $model->update((int) $existing, ['limit_amount' => $data['limit_amount'], 'alert_thresholds' => $thresholds]);
            $this->flash('success', 'Orçamento atualizado.');
        } else {
            $id = $model->create(['category_id' => (int) $data['category_id'], 'user_id' => $userId, 'period_month' => $month, 'limit_amount' => $data['limit_amount'], 'alert_thresholds' => $thresholds]);
            AuditService::log('budget.created', 'budget', $id, null, ['category_id' => (int) $data['category_id'], 'limit' => $data['limit_amount'], 'month' => $month]);
            $this->flash('success', 'Orçamento criado.');
        }
        return $this->redirectRoute('budgets.index', [], ['mes' => substr($month, 0, 7)]);
    }

    public function update(string $id): Response
    {
        $this->requireWrite();
        $data = $this->validate(['limit_amount' => 'required|money', 'warn_at' => 'nullable|integer|between:1,100'], ['limit_amount' => 'limite', 'warn_at' => 'aviso em'], 'budgets.index');
        $model = new Budget();
        $budget = $model->findOrFail((int) $id);
        $model->update((int) $id, ['limit_amount' => $data['limit_amount'], 'alert_thresholds' => [(int) ($data['warn_at'] ?: 80), 100]]);
        AuditService::log('budget.updated', 'budget', (int) $id, ['limit' => $budget['limit_amount']], ['limit' => $data['limit_amount']]);
        $this->flash('success', 'Orçamento atualizado.');
        return $this->redirectRoute('budgets.index', [], ['mes' => substr((string) $budget['period_month'], 0, 7)]);
    }

    public function destroy(string $id): Response
    {
        $this->requireWrite();
        $model = new Budget();
        $budget = $model->findOrFail((int) $id);
        $model->delete((int) $id);
        AuditService::log('budget.deleted', 'budget', (int) $id, ['category_id' => $budget['category_id'], 'limit' => $budget['limit_amount']], null);
        $this->flash('success', 'Orçamento removido.');
        return $this->redirectRoute('budgets.index', [], ['mes' => substr((string) $budget['period_month'], 0, 7)]);
    }

    public function copy(): Response
    {
        $this->requireWrite();
        $month = $this->month();
        $n = BudgetService::copyFromPrevious((int) Auth::householdId(), $month);
        $this->flash('success', $n > 0 ? "{$n} orçamento(s) copiado(s) do mês anterior." : 'Nada para copiar: o mês anterior não tem orçamentos novos.');
        return $this->redirectRoute('budgets.index', [], ['mes' => substr($month, 0, 7)]);
    }

    /** Mês pedido (?mes=AAAA-MM) ou o atual, sempre como primeiro dia. */
    private function month(?string $value = null): string
    {
        $value = $value ?: (string) $this->request->input('mes', $this->request->query('mes', ''));
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return $m[1] . '-' . $m[2] . '-01';
        }
        return (new \DateTimeImmutable('today', user_timezone()))->format('Y-m-01');
    }

    private function requireWrite(): void
    {
        if (!Auth::canWrite()) {
            throw new HttpException(403, 'Seu papel no lar é somente leitura.');
        }
    }
}
