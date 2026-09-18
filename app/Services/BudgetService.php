<?php
// app/Services/BudgetService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Budget;
use App\Models\Category;
use DateTimeImmutable;

/**
 * Orçamento mensal por categoria (e por membro), com gasto acumulado, projeção "neste ritmo estoura no dia X",
 * essencial × supérfluo e regra 50/30/20.
 */
final class BudgetService
{
    public const DEFAULT_THRESHOLDS = [80, 100];

    /** Gasto (pago + pendente) numa categoria e suas filhas no período; $userId filtra pelo responsável. */
    public static function spent(int $householdId, int $categoryId, string $from, string $to, ?int $userId = null): float
    {
        $sql = "SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
                 WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.type = 'expense' AND t.status <> 'scheduled'
                   AND t.date BETWEEN ? AND ? AND (t.category_id = ? OR t.category_id IN (SELECT id FROM categories WHERE parent_id = ?))";
        $params = [$householdId, $from, $to, $categoryId, $categoryId];
        if ($userId !== null) {
            $sql .= ' AND t.responsible_user_id = ?';
            $params[] = $userId;
        }
        return (float) Database::scalar($sql, $params);
    }

    /** Média mensal de gasto na categoria nos N meses completos anteriores a $monthStart. */
    public static function averageMonthly(int $householdId, int $categoryId, string $monthStart, int $months = 3, ?int $userId = null): float
    {
        $start = (new DateTimeImmutable($monthStart))->modify("-{$months} months");
        $end = (new DateTimeImmutable($monthStart))->modify('-1 day');
        return round(self::spent($householdId, $categoryId, $start->format('Y-m-d'), $end->format('Y-m-d'), $userId) / $months, 2);
    }

    /**
     * Orçamentos do mês com gasto, percentual, projeção e situação.
     * @return list<array<string,mixed>>
     */
    public static function overview(int $householdId, string $monthStart, DateTimeImmutable $today): array
    {
        $month = new DateTimeImmutable($monthStart);
        $monthEnd = $month->modify('last day of this month');
        $isCurrent = $today->format('Y-m') === $month->format('Y-m');
        $daysInMonth = (int) $month->format('t');
        $daysElapsed = $isCurrent ? (int) $today->format('j') : ($today > $monthEnd ? $daysInMonth : 0);
        $budgets = Database::select(
            'SELECT b.*, u.name AS user_name FROM budgets b LEFT JOIN users u ON u.id = b.user_id WHERE b.household_id = ? AND b.period_month = ? ORDER BY b.id',
            [$householdId, $month->format('Y-m-01')]
        );
        $categories = Category::forHousehold($householdId)->map();
        $out = [];
        foreach ($budgets as $b) {
            $b = (new Budget())->castRow($b);
            $limit = (float) $b['limit_amount'];
            $spent = self::spent($householdId, (int) $b['category_id'], $month->format('Y-m-d'), $monthEnd->format('Y-m-d'), $b['user_id'] !== null ? (int) $b['user_id'] : null);
            $pct = $limit > 0 ? (int) round($spent / $limit * 100) : 0;
            $thresholds = is_array($b['alert_thresholds']) && $b['alert_thresholds'] !== [] ? array_values(array_map('intval', $b['alert_thresholds'])) : self::DEFAULT_THRESHOLDS;
            sort($thresholds);
            $rate = $daysElapsed > 0 ? $spent / $daysElapsed : 0.0;
            $projected = $isCurrent ? round($rate * $daysInMonth, 2) : $spent;
            $burstDay = $isCurrent && $rate > 0 && $projected > $limit && $spent < $limit ? (int) ceil($limit / $rate) : null;
            $status = $pct >= 100 ? 'over' : ($pct >= $thresholds[0] ? 'warn' : ($isCurrent && $projected > $limit ? 'risk' : 'ok'));
            $cat = $categories[(int) $b['category_id']] ?? null;
            $out[] = $b + [
                'category'      => $cat,
                'category_name' => $cat['full_name'] ?? 'Categoria removida',
                'spent'         => round($spent, 2),
                'remaining'     => round($limit - $spent, 2),
                'pct'           => $pct,
                'projected'     => $projected,
                'burst_day'     => $burstDay,
                'status'        => $status,
                'thresholds'    => $thresholds,
                'daily_allow'   => $isCurrent && $daysInMonth - $daysElapsed > 0 ? round(max(0.0, $limit - $spent) / ($daysInMonth - $daysElapsed), 2) : null,
            ];
        }
        usort($out, static fn(array $a, array $b): int => $b['pct'] <=> $a['pct']);
        return $out;
    }

    /** Copia os orçamentos do mês anterior que ainda não existem no mês alvo. */
    public static function copyFromPrevious(int $householdId, string $monthStart): int
    {
        $month = new DateTimeImmutable($monthStart);
        $prev = $month->modify('-1 month')->format('Y-m-01');
        $rows = Database::select('SELECT * FROM budgets WHERE household_id = ? AND period_month = ?', [$householdId, $prev]);
        $n = 0;
        $model = Budget::forHousehold($householdId);
        foreach ($rows as $r) {
            $exists = Database::scalar('SELECT id FROM budgets WHERE household_id = ? AND period_month = ? AND category_id = ? AND (user_id <=> ?)', [$householdId, $month->format('Y-m-01'), (int) $r['category_id'], $r['user_id']]);
            if ($exists !== null) {
                continue;
            }
            $model->create(['category_id' => (int) $r['category_id'], 'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null, 'period_month' => $month->format('Y-m-01'), 'limit_amount' => $r['limit_amount'], 'alert_thresholds' => $r['alert_thresholds'] !== null ? json_decode((string) $r['alert_thresholds'], true) : null]);
            $n++;
        }
        return $n;
    }

    /** Meses que têm orçamento (para o seletor). @return list<string> */
    public static function months(int $householdId): array
    {
        return array_map('strval', array_column(Database::select('SELECT DISTINCT period_month FROM budgets WHERE household_id = ? ORDER BY period_month DESC', [$householdId]), 'period_month'));
    }

    /**
     * Essencial × supérfluo do mês e regra 50/30/20 com semáforo.
     * @return array<string,mixed>
     */
    public static function essentialSplit(int $householdId, string $monthStart): array
    {
        $month = new DateTimeImmutable($monthStart);
        $from = $month->format('Y-m-01');
        $to = $month->modify('last day of this month')->format('Y-m-d');
        $row = Database::selectOne(
            "SELECT
                COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount END), 0) AS income,
                COALESCE(SUM(CASE WHEN t.type = 'expense' AND COALESCE(c.is_essential, p.is_essential, 0) = 1 THEN t.amount END), 0) AS essential,
                COALESCE(SUM(CASE WHEN t.type = 'expense' AND COALESCE(c.is_essential, p.is_essential, 0) = 0 THEN t.amount END), 0) AS superfluous
               FROM transactions t
               LEFT JOIN categories c ON c.id = t.category_id
               LEFT JOIN categories p ON p.id = c.parent_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.status <> 'scheduled' AND t.date BETWEEN ? AND ?",
            [$householdId, $from, $to]
        ) ?? ['income' => 0, 'essential' => 0, 'superfluous' => 0];
        $income = (float) $row['income'];
        $essential = (float) $row['essential'];
        $superfluous = (float) $row['superfluous'];
        $expense = $essential + $superfluous;
        $savings = max(0.0, $income - $expense);
        $pct = static fn(float $v): int => $income > 0 ? (int) round($v / $income * 100) : 0;
        $needs = $pct($essential);
        $wants = $pct($superfluous);
        $save = $pct($savings);
        if ($income <= 0) {
            $light = 'none';
        } elseif ($needs <= 50 && $wants <= 30 && $save >= 20) {
            $light = 'green';
        } elseif ($needs <= 60 && $wants <= 40 && $save >= 10) {
            $light = 'yellow';
        } else {
            $light = 'red';
        }
        return [
            'income' => $income, 'essential' => $essential, 'superfluous' => $superfluous, 'expense' => $expense, 'savings' => $savings,
            'needs_pct' => $needs, 'wants_pct' => $wants, 'savings_pct' => $save,
            'superfluous_share' => $expense > 0 ? (int) round($superfluous / $expense * 100) : 0,
            'light' => $light,
        ];
    }
}
