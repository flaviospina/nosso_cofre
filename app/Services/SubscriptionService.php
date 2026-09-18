<?php
// app/Services/SubscriptionService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\RecurringRule;
use DateTimeImmutable;

/**
 * Radar de assinaturas: recorrências marcadas como assinatura + cobranças repetidas detectadas nos lançamentos.
 * Sinaliza duplicidades (mesma descrição em mais de uma regra/conta), aumento de valor (> 5 %) e
 * serviços sem uso confirmado há 60 dias.
 */
final class SubscriptionService
{
    public const UNUSED_DAYS = 60;
    public const INCREASE_PCT = 5.0;

    /** @return array{items:list<array<string,mixed>>,detected:list<array<string,mixed>>,monthly_total:float,alerts:int} */
    public static function radar(int $householdId, DateTimeImmutable $today): array
    {
        $rules = RecurringRule::forHousehold($householdId)->all(['is_subscription' => 1], 'description', 'ASC');
        $names = [];
        foreach ($rules as $r) {
            $key = CategoryService::normalize((string) $r['description']);
            $names[$key] = ($names[$key] ?? 0) + ((int) $r['is_active'] === 1 ? 1 : 0);
        }
        $items = [];
        $total = 0.0;
        $alerts = 0;
        foreach ($rules as $r) {
            $key = CategoryService::normalize((string) $r['description']);
            $paid = Database::select("SELECT amount, date FROM transactions WHERE household_id = ? AND recurring_id = ? AND status = 'paid' AND deleted_at IS NULL ORDER BY date DESC LIMIT 2", [$householdId, (int) $r['id']]);
            $last = isset($paid[0]) ? (float) $paid[0]['amount'] : null;
            $previous = isset($paid[1]) ? (float) $paid[1]['amount'] : null;
            $monthly = round((float) $r['expected_amount'] * RecurrenceService::monthlyFactor($r), 2);
            $flags = [];
            if ((int) $r['is_active'] === 1) {
                if (($names[$key] ?? 0) > 1) {
                    $flags[] = ['kind' => 'duplicate', 'label' => 'Cobrança duplicada', 'detail' => 'Mais de uma assinatura com o mesmo nome no lar.'];
                }
                if ($last !== null && $previous !== null && $previous > 0 && ($last - $previous) / $previous * 100 > self::INCREASE_PCT) {
                    $flags[] = ['kind' => 'increase', 'label' => 'Aumento de preço', 'detail' => money($previous) . ' → ' . money($last) . ' (' . round(($last - $previous) / $previous * 100) . '%)'];
                }
                if ($last !== null && $last > (float) $r['expected_amount'] * (1 + self::INCREASE_PCT / 100)) {
                    $flags[] = ['kind' => 'increase', 'label' => 'Pagou mais que o esperado', 'detail' => 'Esperado ' . money($r['expected_amount']) . ', pago ' . money($last) . '.'];
                }
                $usage = !empty($r['last_usage_confirmed_at']) ? new DateTimeImmutable((string) $r['last_usage_confirmed_at']) : null;
                $daysSince = $usage !== null ? (int) $usage->diff($today)->format('%a') : null;
                if ($usage === null || $daysSince > self::UNUSED_DAYS) {
                    $flags[] = ['kind' => 'unused', 'label' => 'Sem uso registrado', 'detail' => $usage === null ? 'Você nunca confirmou que usa este serviço.' : "Último uso confirmado há {$daysSince} dias."];
                }
                $total += $monthly;
            }
            $alerts += count(array_filter($flags, static fn(array $f): bool => $f['kind'] !== 'unused'));
            $r['monthly_amount'] = $monthly;
            $r['last_paid'] = $last;
            $r['flags'] = $flags;
            $items[] = $r;
        }
        $detected = self::detectFromTransactions($householdId, $today, array_keys($names));
        foreach ($detected as $d) {
            $total += $d['monthly_amount'];
        }
        return ['items' => $items, 'detected' => $detected, 'monthly_total' => round($total, 2), 'alerts' => $alerts];
    }

    /**
     * Cobranças que se repetem nos últimos 6 meses (≥ 3 meses distintos, valor variando até 10 %) e não estão ligadas a nenhuma regra.
     * @param list<string> $knownNames descrições normalizadas já cadastradas
     * @return list<array<string,mixed>>
     */
    public static function detectFromTransactions(int $householdId, DateTimeImmutable $today, array $knownNames = [], ?int $viewerId = null): array
    {
        [$visibleSql, $visibleParams] = TransactionPolicy::visibleSql('t', TransactionPolicy::viewer($viewerId), $householdId);
        $rows = Database::select(
            "SELECT t.description, t.amount, t.date, t.category_id, t.account_id FROM transactions t
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.type = 'expense' AND t.recurring_id IS NULL AND t.status <> 'scheduled' AND t.date >= ? AND {$visibleSql}
              ORDER BY t.date",
            array_merge([$householdId, $today->modify('-6 months')->format('Y-m-d')], $visibleParams)
        );
        $groups = [];
        foreach ($rows as $row) {
            $key = CategoryService::normalize((string) $row['description']);
            if ($key === '' || in_array($key, $knownNames, true)) {
                continue;
            }
            $groups[$key][] = $row;
        }
        $out = [];
        foreach ($groups as $key => $list) {
            $months = array_unique(array_map(static fn(array $r): string => substr((string) $r['date'], 0, 7), $list));
            if (count($months) < 3) {
                continue;
            }
            $amounts = array_map(static fn(array $r): float => (float) $r['amount'], $list);
            $avg = array_sum($amounts) / count($amounts);
            if ($avg <= 0 || (max($amounts) - min($amounts)) / $avg > 0.10) {
                continue;
            }
            $last = end($list);
            $lastAmount = (float) $last['amount'];
            $flags = [];
            if ($lastAmount > $avg * (1 + self::INCREASE_PCT / 100)) {
                $flags[] = ['kind' => 'increase', 'label' => 'Aumento de preço', 'detail' => 'Média ' . money($avg) . ', última ' . money($lastAmount) . '.'];
            }
            $out[] = [
                'key'            => $key,
                'description'    => (string) $last['description'],
                'monthly_amount' => round($avg, 2),
                'months'         => count($months),
                'last_date'      => (string) $last['date'],
                'category_id'    => $last['category_id'] !== null ? (int) $last['category_id'] : null,
                'account_id'     => (int) $last['account_id'],
                'day_of_month'   => (int) substr((string) $last['date'], 8, 2),
                'flags'          => $flags,
            ];
        }
        usort($out, static fn(array $a, array $b): int => $b['monthly_amount'] <=> $a['monthly_amount']);
        return $out;
    }
}
