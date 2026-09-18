<?php
// app/Controllers/DashboardController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Response;
use App\Models\Category;
use App\Models\HouseholdMember;
use App\Models\Transaction;
use App\Services\AccountService;
use App\Services\TransactionPolicy;

/**
 * Painel inicial. Nesta fase mostra saldos, o resumo do mês, contas a pagar nos próximos dias e os últimos lançamentos;
 * os 11 indicadores e os gráficos entram na fase 6.
 */
final class DashboardController extends Controller
{
    public function index(): Response
    {
        $household = Auth::household();
        $householdId = (int) Auth::householdId();
        $members = (new HouseholdMember())->activeMembers();
        $accounts = AccountService::withBalances($householdId, true);
        $stage = (string) ($household['settings']['onboarding'] ?? 'setup');
        $today = new \DateTimeImmutable('today', user_timezone());
        $from = $today->modify('first day of this month')->format('Y-m-d');
        $to = $today->modify('last day of this month')->format('Y-m-d');
        $month = Database::selectOne(
            "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount END), 0) AS income,
                    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expense,
                    COALESCE(SUM(CASE WHEN type = 'expense' AND status <> 'paid' THEN amount END), 0) AS expense_open,
                    COUNT(*) AS n
               FROM transactions WHERE household_id = ? AND deleted_at IS NULL AND date BETWEEN ? AND ?",
            [$householdId, $from, $to]
        ) ?? ['income' => 0, 'expense' => 0, 'expense_open' => 0, 'n' => 0];
        $model = new Transaction();
        $upcoming = Database::select(
            "SELECT t.*, a.name AS account_name FROM transactions t JOIN accounts a ON a.id = t.account_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.type = 'expense' AND t.status <> 'paid' AND t.date <= ?
              ORDER BY t.date, t.id LIMIT 8",
            [$householdId, $today->modify('+7 days')->format('Y-m-d')]
        );
        $latest = Database::select(
            'SELECT t.*, a.name AS account_name, u.name AS responsible_name, u.color AS responsible_color FROM transactions t JOIN accounts a ON a.id = t.account_id LEFT JOIN users u ON u.id = t.responsible_user_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL ORDER BY t.created_at DESC, t.id DESC LIMIT 6',
            [$householdId]
        );
        return $this->view('dashboard/index', [
            'title'      => 'Início',
            'household'  => $household,
            'members'    => $members,
            'accounts'   => $accounts,
            'totals'     => AccountService::totals($accounts),
            'month'      => $month,
            'monthLabel' => month_name((int) $today->format('n')) . ' de ' . $today->format('Y'),
            'upcoming'   => array_map(static fn(array $r): array => TransactionPolicy::mask($model->castRow($r)), $upcoming),
            'latest'     => array_map(static fn(array $r): array => TransactionPolicy::mask($model->castRow($r)), $latest),
            'categories' => (new Category())->map(),
            'today'      => $today->format('Y-m-d'),
            'stage'      => $stage,
            'canWrite'   => TransactionPolicy::canCreate(),
        ]);
    }
}
