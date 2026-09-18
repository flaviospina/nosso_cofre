<?php
// app/Services/SimulatorService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\RecurringRule;
use DateTimeImmutable;

/**
 * Simulador "e se": corta R$ X da categoria Y e/ou cancela assinaturas → quanto sobra em 6/12 meses,
 * meses de reserva cobertos e impacto na primeira meta ativa.
 */
final class SimulatorService
{
    /** Números de partida do lar (médias dos últimos 3 meses completos; cai no mês atual se não houver histórico). */
    /** @return array<string,mixed> */
    public static function baseline(int $householdId, DateTimeImmutable $today): array
    {
        $monthStart = $today->format('Y-m-01');
        $from = (new DateTimeImmutable($monthStart))->modify('-3 months')->format('Y-m-d');
        $to = (new DateTimeImmutable($monthStart))->modify('-1 day')->format('Y-m-d');
        $months = 3;
        $row = self::sums($householdId, $from, $to);
        if ((float) $row['income'] + (float) $row['expense'] <= 0) {
            $row = self::sums($householdId, $monthStart, $today->modify('last day of this month')->format('Y-m-d'));
            $months = 1;
        }
        $income = (float) $row['income'] / $months;
        $expense = (float) $row['expense'] / $months;
        $essential = (float) $row['essential'] / $months;
        $reserve = 0.0;
        foreach (AccountService::withBalances($householdId, true) as $a) {
            if (in_array($a['type'], ['savings', 'investment'], true)) {
                $reserve += (float) $a['balance'];
            }
        }
        $byCategory = Database::select(
            "SELECT COALESCE(p.id, c.id) AS id, COALESCE(p.name, c.name) AS name, SUM(t.amount) / ? AS monthly
               FROM transactions t JOIN categories c ON c.id = t.category_id LEFT JOIN categories p ON p.id = c.parent_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.type = 'expense' AND t.status <> 'scheduled' AND t.date BETWEEN ? AND ?
              GROUP BY COALESCE(p.id, c.id), COALESCE(p.name, c.name) ORDER BY monthly DESC",
            [$months, $householdId, $months === 3 ? $from : $monthStart, $months === 3 ? $to : $today->modify('last day of this month')->format('Y-m-d')]
        );
        $goal = Database::selectOne("SELECT id, name, target_amount, saved_amount, deadline FROM goals WHERE household_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY deadline IS NULL, deadline, id LIMIT 1", [$householdId]);
        return [
            'months_used' => $months, 'income' => round($income, 2), 'expense' => round($expense, 2), 'essential' => round($essential, 2),
            'surplus' => round($income - $expense, 2), 'reserve' => round($reserve, 2),
            'categories' => array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'monthly' => round((float) $r['monthly'], 2)], $byCategory),
            'subscriptions' => array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'description' => (string) $r['description'], 'monthly' => round((float) $r['expected_amount'] * RecurrenceService::monthlyFactor($r), 2)], RecurringRule::forHousehold($householdId)->all(['is_subscription' => 1, 'is_active' => 1], 'expected_amount', 'DESC')),
            'goal' => $goal,
        ];
    }

    /**
     * @param array<int,float> $cuts categoria → corte mensal
     * @param list<int> $cancel ids de assinaturas canceladas
     * @return array<string,mixed>
     */
    public static function simulate(array $baseline, array $cuts, array $cancel, int $horizon = 12): array
    {
        $catMonthly = [];
        foreach ($baseline['categories'] as $c) {
            $catMonthly[$c['id']] = $c['monthly'];
        }
        $saving = 0.0;
        $lines = [];
        foreach ($cuts as $categoryId => $amount) {
            $amount = max(0.0, (float) $amount);
            if ($amount <= 0) {
                continue;
            }
            $cap = $catMonthly[(int) $categoryId] ?? null;
            $applied = $cap !== null ? min($amount, $cap) : $amount;
            $name = null;
            foreach ($baseline['categories'] as $c) {
                if ($c['id'] === (int) $categoryId) {
                    $name = $c['name'];
                }
            }
            $lines[] = ['label' => 'Cortar ' . money($applied) . '/mês de ' . ($name ?? 'categoria'), 'monthly' => round($applied, 2), 'capped' => $cap !== null && $amount > $cap];
            $saving += $applied;
        }
        foreach ($baseline['subscriptions'] as $s) {
            if (in_array($s['id'], $cancel, true)) {
                $lines[] = ['label' => 'Cancelar ' . $s['description'], 'monthly' => $s['monthly'], 'capped' => false];
                $saving += $s['monthly'];
            }
        }
        $saving = round($saving, 2);
        $surplusNow = (float) $baseline['surplus'];
        $surplusNew = $surplusNow + $saving;
        $essential = max(1.0, (float) $baseline['essential'] > 0 ? (float) $baseline['essential'] : (float) $baseline['expense']);
        $reserve = (float) $baseline['reserve'];
        $goal = $baseline['goal'];
        $goalGap = $goal !== null ? max(0.0, (float) $goal['target_amount'] - (float) $goal['saved_amount']) : null;
        $monthsTo = static fn(float $gap, float $monthly): ?int => $gap <= 0 ? 0 : ($monthly > 0 ? (int) ceil($gap / $monthly) : null);
        $horizons = [];
        foreach ([6, 12] as $h) {
            $horizons[$h] = [
                'saved_extra'   => round($saving * $h, 2),
                'surplus_now'   => round($surplusNow * $h, 2),
                'surplus_new'   => round($surplusNew * $h, 2),
                'reserve_now'   => round(($reserve + max(0.0, $surplusNow) * $h) / $essential, 1),
                'reserve_new'   => round(($reserve + max(0.0, $surplusNew) * $h) / $essential, 1),
            ];
        }
        return [
            'lines'           => $lines,
            'monthly_saving'  => $saving,
            'surplus_now'     => round($surplusNow, 2),
            'surplus_new'     => round($surplusNew, 2),
            'reserve_months'  => round($reserve / $essential, 1),
            'horizons'        => $horizons,
            'goal'            => $goal !== null ? ['name' => $goal['name'], 'gap' => round($goalGap, 2), 'months_now' => $monthsTo($goalGap, max(0.0, $surplusNow)), 'months_new' => $monthsTo($goalGap, max(0.0, $surplusNew))] : null,
            'horizon'         => $horizon,
        ];
    }

    /** @return array<string,mixed> */
    private static function sums(int $householdId, string $from, string $to): array
    {
        return Database::selectOne(
            "SELECT COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount END), 0) AS income,
                    COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0) AS expense,
                    COALESCE(SUM(CASE WHEN t.type = 'expense' AND COALESCE(c.is_essential, p.is_essential, 0) = 1 THEN t.amount END), 0) AS essential
               FROM transactions t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN categories p ON p.id = c.parent_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.status <> 'scheduled' AND t.date BETWEEN ? AND ?",
            [$householdId, $from, $to]
        ) ?? ['income' => 0, 'expense' => 0, 'essential' => 0];
    }
}
