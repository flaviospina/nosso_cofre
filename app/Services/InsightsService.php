<?php
// app/Services/InsightsService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Transaction;
use DateTimeImmutable;

/**
 * Indicadores do painel (§6.4): saldo do mês e projetado, receitas × despesas em 12 meses, despesas por categoria
 * e por membro, contas a vencer, orçamentos em risco, taxa de poupança e "idade do dinheiro", supérfluo,
 * metas e plano, radar, eventos previstos e score de saúde financeira (0–100) com explicação.
 */
final class InsightsService
{
    /** @return array<string,mixed> */
    public static function dashboard(int $householdId, string $monthStart, ?int $memberId, DateTimeImmutable $today): array
    {
        $month = new DateTimeImmutable($monthStart);
        $from = $month->format('Y-m-01');
        $to = $month->modify('last day of this month')->format('Y-m-d');
        $memberSql = $memberId !== null ? ' AND t.responsible_user_id = ?' : '';
        $memberParams = $memberId !== null ? [$memberId] : [];

        // 1) Saldo do mês (pagos + pendentes) e projetado (inclui agendados)
        $sums = Database::selectOne(
            "SELECT
                COALESCE(SUM(CASE WHEN t.type = 'income' AND t.status <> 'scheduled' THEN t.amount END), 0) AS income,
                COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.status <> 'scheduled' THEN t.amount END), 0) AS expense,
                COALESCE(SUM(CASE WHEN t.type = 'income' AND t.status = 'paid' THEN t.amount END), 0) AS income_paid,
                COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.status = 'paid' THEN t.amount END), 0) AS expense_paid,
                COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount END), 0) AS income_all,
                COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0) AS expense_all
               FROM transactions t WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.date BETWEEN ? AND ?{$memberSql}",
            array_merge([$householdId, $from, $to], $memberParams)
        ) ?? [];
        $income = (float) ($sums['income'] ?? 0);
        $expense = (float) ($sums['expense'] ?? 0);
        $monthData = [
            'income' => $income, 'expense' => $expense, 'balance' => $income - $expense,
            'income_paid' => (float) ($sums['income_paid'] ?? 0), 'expense_paid' => (float) ($sums['expense_paid'] ?? 0),
            'balance_paid' => (float) ($sums['income_paid'] ?? 0) - (float) ($sums['expense_paid'] ?? 0),
            'projected' => (float) ($sums['income_all'] ?? 0) - (float) ($sums['expense_all'] ?? 0),
            'pending_expense' => (float) ($sums['expense_all'] ?? 0) - (float) ($sums['expense_paid'] ?? 0),
        ];

        // 2) Série de 12 meses até o mês escolhido
        $seriesFrom = $month->modify('-11 months')->format('Y-m-01');
        $series = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = $month->modify("-{$i} months");
            $series[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'label' => month_name((int) $m->format('n'), true) . '/' . $m->format('y'), 'income' => 0.0, 'expense' => 0.0];
        }
        foreach (Database::select(
            "SELECT DATE_FORMAT(t.date, '%Y-%m') AS ym, t.type, SUM(t.amount) AS total FROM transactions t
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.status <> 'scheduled' AND t.type IN ('income','expense') AND t.date BETWEEN ? AND ?{$memberSql}
              GROUP BY ym, t.type",
            array_merge([$householdId, $seriesFrom, $to], $memberParams)
        ) as $r) {
            if (isset($series[$r['ym']])) {
                $series[$r['ym']][$r['type']] = round((float) $r['total'], 2);
            }
        }

        // 3) Despesas por categoria (pai) e por membro
        $byCategory = [];
        $catTotal = 0.0;
        foreach (Database::select(
            "SELECT COALESCE(p.id, c.id) AS id, COALESCE(p.name, c.name, 'Sem categoria') AS name, COALESCE(p.color, c.color) AS color, COALESCE(p.icon, c.icon) AS icon, SUM(t.amount) AS total
               FROM transactions t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN categories p ON p.id = c.parent_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.type = 'expense' AND t.status <> 'scheduled' AND t.date BETWEEN ? AND ?{$memberSql}
              GROUP BY COALESCE(p.id, c.id), COALESCE(p.name, c.name, 'Sem categoria'), COALESCE(p.color, c.color), COALESCE(p.icon, c.icon) ORDER BY total DESC",
            array_merge([$householdId, $from, $to], $memberParams)
        ) as $r) {
            $catTotal += (float) $r['total'];
            $byCategory[] = ['id' => $r['id'] !== null ? (int) $r['id'] : null, 'name' => (string) $r['name'], 'color' => $r['color'] ?: '#94a3b8', 'icon' => $r['icon'] ?: 'tag', 'amount' => round((float) $r['total'], 2)];
        }
        if (count($byCategory) > 8) {
            $rest = array_slice($byCategory, 7);
            $byCategory = array_slice($byCategory, 0, 7);
            $byCategory[] = ['id' => null, 'name' => 'Outras', 'color' => '#94a3b8', 'icon' => 'three-dots', 'amount' => round(array_sum(array_column($rest, 'amount')), 2)];
        }
        foreach ($byCategory as &$c) {
            $c['pct'] = $catTotal > 0 ? (int) round($c['amount'] / $catTotal * 100) : 0;
        }
        unset($c);
        $byMember = [];
        foreach (Database::select(
            "SELECT t.responsible_user_id AS user_id, COALESCE(u.name, 'Todos (da casa)') AS name, COALESCE(u.color, '#6c757d') AS color, SUM(t.amount) AS total
               FROM transactions t LEFT JOIN users u ON u.id = t.responsible_user_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.type = 'expense' AND t.status <> 'scheduled' AND t.date BETWEEN ? AND ?
              GROUP BY t.responsible_user_id, u.name, u.color ORDER BY total DESC",
            [$householdId, $from, $to]
        ) as $r) {
            $byMember[] = ['user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null, 'name' => (string) $r['name'], 'color' => (string) $r['color'], 'amount' => round((float) $r['total'], 2), 'pct' => $catTotal > 0 ? (int) round((float) $r['total'] / $catTotal * 100) : 0];
        }

        // 4) Contas a vencer (7 dias) com urgência
        $model = new Transaction();
        $upcoming = [];
        foreach (Database::select(
            "SELECT t.*, a.name AS account_name FROM transactions t JOIN accounts a ON a.id = t.account_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.type = 'expense' AND t.status <> 'paid' AND t.date <= ?{$memberSql}
              ORDER BY t.date, t.id LIMIT 10",
            array_merge([$householdId, $today->modify('+7 days')->format('Y-m-d')], $memberParams)
        ) as $r) {
            $r = TransactionPolicy::mask($model->castRow($r));
            $r['urgency'] = $r['date'] < $today->format('Y-m-d') ? 'late' : ($r['date'] === $today->format('Y-m-d') ? 'today' : 'soon');
            $upcoming[] = $r;
        }
        $overdue = count(array_filter($upcoming, static fn(array $u): bool => $u['urgency'] === 'late'));

        // 5) Orçamentos mais próximos de estourar
        $budgets = array_slice(BudgetService::overview($householdId, $from, $today), 0, 5);
        $allBudgets = BudgetService::overview($householdId, $from, $today);
        $budgetsOver = count(array_filter($allBudgets, static fn(array $b): bool => $b['status'] === 'over'));

        // 6) Taxa de poupança e idade do dinheiro
        $savingsRate = $income > 0 ? (int) round(($income - $expense) / $income * 100) : null;
        $liquid = 0.0;
        $reserve = 0.0;
        foreach (AccountService::withBalances($householdId, true) as $a) {
            if (in_array($a['type'], ['checking', 'cash', 'savings'], true)) {
                $liquid += (float) $a['balance'];
            }
            if (in_array($a['type'], ['savings', 'investment'], true)) {
                $reserve += (float) $a['balance'];
            }
        }
        $daily = (float) Database::scalar("SELECT COALESCE(SUM(amount), 0) / 90 FROM transactions WHERE household_id = ? AND deleted_at IS NULL AND type = 'expense' AND status = 'paid' AND date BETWEEN ? AND ?", [$householdId, $today->modify('-90 days')->format('Y-m-d'), $today->format('Y-m-d')]);
        $ageOfMoney = ['days' => $daily > 0 ? (int) floor(max(0.0, $liquid) / $daily) : null, 'liquid' => round($liquid, 2), 'daily' => round($daily, 2)];

        // 7) Supérfluo do mês, 8) metas e plano, 9) radar, 10) eventos
        $split = BudgetService::essentialSplit($householdId, $from);
        $goals = GoalService::withProgress($householdId, $today, false);
        $plan = SavingsActionService::overview($householdId, $today);
        $radar = SubscriptionService::radar($householdId, $today);
        $events = RecurrenceService::upcomingEvents($householdId, $today);
        $essentialMonthly = max(1.0, (float) Database::scalar(
            "SELECT COALESCE(SUM(t.amount), 0) / 3 FROM transactions t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN categories p ON p.id = c.parent_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.type = 'expense' AND t.status <> 'scheduled' AND COALESCE(c.is_essential, p.is_essential, 0) = 1 AND t.date >= ? AND t.date < ?",
            [$householdId, $month->modify('-3 months')->format('Y-m-01'), $from]
        ) ?: ($split['essential'] > 0 ? $split['essential'] : 1.0));
        $reserveMonths = round($reserve / $essentialMonthly, 1);

        // 11) Score
        $score = self::score([
            'savings_rate'        => $savingsRate,
            'budgets_total'       => count($allBudgets),
            'budgets_over'        => $budgetsOver,
            'overdue_count'       => $overdue,
            'reserve_months'      => $reserveMonths,
            'superfluous_share'   => (int) $split['superfluous_share'],
            'subscription_alerts' => (int) $radar['alerts'],
            'has_expenses'        => $expense > 0,
        ]);

        return [
            'month'          => $monthData,
            'series'         => array_values($series),
            'by_category'    => $byCategory,
            'by_member'      => $byMember,
            'upcoming'       => $upcoming,
            'overdue'        => $overdue,
            'budgets'        => $budgets,
            'budgets_total'  => count($allBudgets),
            'savings_rate'   => $savingsRate,
            'age_of_money'   => $ageOfMoney,
            'superfluous'    => ['amount' => (float) $split['superfluous'], 'share' => (int) $split['superfluous_share'], 'essential' => (float) $split['essential']],
            'goals'          => array_slice($goals, 0, 4),
            'plan'           => $plan,
            'radar'          => ['monthly_total' => $radar['monthly_total'], 'alerts' => $radar['alerts'], 'count' => count(array_filter($radar['items'], static fn(array $i): bool => (int) $i['is_active'] === 1)), 'detected' => count($radar['detected'])],
            'events'         => $events,
            'reserve'        => ['amount' => round($reserve, 2), 'months' => $reserveMonths],
            'score'          => $score,
        ];
    }

    /**
     * Score de saúde financeira (0–100), com a explicação de cada parcela.
     * @param array<string,mixed> $in
     * @return array{total:int,level:string,items:list<array{label:string,points:int,max:int,detail:string}>}
     */
    public static function score(array $in): array
    {
        $items = [];
        // Poupança: 25 pontos ao guardar 20 % ou mais da receita
        $rate = $in['savings_rate'];
        if ($rate === null) {
            $items[] = ['label' => 'Taxa de poupança', 'points' => 0, 'max' => 25, 'detail' => 'Sem receitas no mês: registre as entradas para pontuar.'];
        } else {
            $p = (int) round(max(0, min(20, $rate)) / 20 * 25);
            $items[] = ['label' => 'Taxa de poupança', 'points' => $p, 'max' => 25, 'detail' => "Você guardou {$rate}% da receita (meta: 20% ou mais)."];
        }
        // Orçamentos: 20 pontos se nenhum estourou
        $total = (int) $in['budgets_total'];
        $over = (int) $in['budgets_over'];
        if ($total === 0) {
            $items[] = ['label' => 'Orçamentos', 'points' => 10, 'max' => 20, 'detail' => 'Sem limites definidos: crie orçamentos para ganhar os 20 pontos.'];
        } else {
            $p = (int) round(20 * (1 - $over / $total));
            $items[] = ['label' => 'Orçamentos', 'points' => $p, 'max' => 20, 'detail' => $over === 0 ? "Nenhum dos {$total} limites estourou." : "{$over} de {$total} limite(s) estourado(s)."];
        }
        // Contas em dia: 15 pontos, −5 por conta atrasada
        $late = (int) $in['overdue_count'];
        $items[] = ['label' => 'Contas em dia', 'points' => max(0, 15 - 5 * $late), 'max' => 15, 'detail' => $late === 0 ? 'Nenhuma conta atrasada.' : "{$late} conta(s) vencida(s) sem pagamento."];
        // Reserva: 20 pontos com 6 meses cobertos
        $months = (float) $in['reserve_months'];
        $items[] = ['label' => 'Reserva de emergência', 'points' => (int) round(min(6.0, max(0.0, $months)) / 6 * 20), 'max' => 20, 'detail' => 'Poupança + investimentos cobrem ' . number_format($months, 1, ',', '.') . ' mês(es) de gastos essenciais (meta: 6).'];
        // Supérfluo: 10 pontos até 30 % das despesas; zero a partir de 60 %
        $share = (int) $in['superfluous_share'];
        $p = !empty($in['has_expenses']) ? (int) round(10 * (1 - max(0, min(30, $share - 30)) / 30)) : 5;
        $items[] = ['label' => 'Gasto supérfluo', 'points' => $p, 'max' => 10, 'detail' => !empty($in['has_expenses']) ? "{$share}% das despesas do mês são supérfluas (meta: até 30%)." : 'Sem despesas registradas no mês.'];
        // Assinaturas: 10 pontos sem alertas
        $alerts = (int) $in['subscription_alerts'];
        $items[] = ['label' => 'Assinaturas', 'points' => max(0, 10 - 5 * $alerts), 'max' => 10, 'detail' => $alerts === 0 ? 'Nenhuma duplicidade ou aumento no radar.' : "{$alerts} alerta(s) no radar de assinaturas."];
        $sum = array_sum(array_column($items, 'points'));
        $level = $sum >= 80 ? 'Ótima' : ($sum >= 60 ? 'Boa' : ($sum >= 40 ? 'Atenção' : 'Crítica'));
        return ['total' => $sum, 'level' => $level, 'items' => $items];
    }
}
