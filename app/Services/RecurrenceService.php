<?php
// app/Services/RecurrenceService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Models\RecurringRule;
use App\Models\Transaction;
use DateTimeImmutable;

/**
 * Motor de recorrências: calcula ocorrências (semanal, mensal, anual, a cada N dias) e gera lançamentos
 * "agendados" com antecedência (generate_days_ahead). Idempotente: nunca cria duas vezes a mesma (regra, data).
 * Roda no cron, ao abrir /recorrencias e sob demanda ("Gerar agora").
 */
final class RecurrenceService
{
    /**
     * Primeira ocorrência da regra em ou após $from (respeita start_date e end_date). Null = não há mais ocorrências.
     * @param array<string,mixed> $rule
     */
    public static function nextOccurrence(array $rule, DateTimeImmutable $from): ?DateTimeImmutable
    {
        $start = new DateTimeImmutable((string) $rule['start_date']);
        $from = $from < $start ? $start : $from->setTime(0, 0);
        $end = !empty($rule['end_date']) ? new DateTimeImmutable((string) $rule['end_date']) : null;
        $interval = max(1, (int) ($rule['interval_count'] ?? 1));
        $candidate = match ((string) $rule['frequency']) {
            'weekly'  => self::nextWeekly($from, (int) ($rule['day_of_week'] ?: (int) $start->format('N'))),
            'yearly'  => self::nextYearly($from, (int) ($rule['month_of_year'] ?: (int) $start->format('n')), (int) ($rule['day_of_month'] ?: (int) $start->format('j'))),
            'custom'  => self::nextCustom($from, $start, $interval),
            default   => self::nextMonthly($from, (int) ($rule['day_of_month'] ?: (int) $start->format('j')), $interval, $start),
        };
        if ($end !== null && $candidate > $end) {
            return null;
        }
        return $candidate;
    }

    private static function nextMonthly(DateTimeImmutable $from, int $day, int $interval, DateTimeImmutable $start): DateTimeImmutable
    {
        // Passo em meses a partir do mês de início (interval_count > 1 = a cada N meses)
        $monthsSinceStart = ((int) $from->format('Y') - (int) $start->format('Y')) * 12 + ((int) $from->format('n') - (int) $start->format('n'));
        $k = (int) floor(max(0, $monthsSinceStart) / $interval);
        for ($i = 0; $i < 3; $i++) {
            $month = $start->modify('first day of this month')->modify('+' . (($k + $i) * $interval) . ' months');
            $candidate = self::clampDay($month, $day);
            if ($candidate >= $from) {
                return $candidate;
            }
        }
        return self::clampDay($start->modify('first day of this month')->modify('+' . (($k + 3) * $interval) . ' months'), $day);
    }

    private static function nextWeekly(DateTimeImmutable $from, int $dayOfWeek): DateTimeImmutable
    {
        $dayOfWeek = max(1, min(7, $dayOfWeek));
        $diff = ($dayOfWeek - (int) $from->format('N') + 7) % 7;
        return $from->modify("+{$diff} days");
    }

    private static function nextYearly(DateTimeImmutable $from, int $month, int $day): DateTimeImmutable
    {
        $month = max(1, min(12, $month));
        $candidate = self::clampDay($from->setDate((int) $from->format('Y'), $month, 1), $day);
        if ($candidate < $from) {
            $candidate = self::clampDay($from->setDate((int) $from->format('Y') + 1, $month, 1), $day);
        }
        return $candidate;
    }

    private static function nextCustom(DateTimeImmutable $from, DateTimeImmutable $start, int $intervalDays): DateTimeImmutable
    {
        $days = (int) $start->diff($from)->format('%a');
        $k = (int) ceil($days / $intervalDays);
        return $start->modify('+' . ($k * $intervalDays) . ' days');
    }

    /** Dia do mês limitado ao último dia (31 → 30/28/29). */
    public static function clampDay(DateTimeImmutable $monthStart, int $day): DateTimeImmutable
    {
        $last = (int) $monthStart->format('t');
        return $monthStart->setDate((int) $monthStart->format('Y'), (int) $monthStart->format('n'), max(1, min($day, $last)));
    }

    /** Quantas ocorrências por mês, em média (para o radar de assinaturas e o simulador). */
    /** @param array<string,mixed> $rule */
    public static function monthlyFactor(array $rule): float
    {
        $interval = max(1, (int) ($rule['interval_count'] ?? 1));
        return match ((string) $rule['frequency']) {
            'weekly' => 52 / 12,
            'yearly' => 1 / 12,
            'custom' => 30.4375 / $interval,
            default  => 1 / $interval,
        };
    }

    /**
     * Gera os lançamentos agendados de todas as regras ativas do lar até hoje + generate_days_ahead.
     * @return int quantidade criada
     */
    public static function generate(int $householdId, ?DateTimeImmutable $today = null): int
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $rules = RecurringRule::forHousehold($householdId)->all(['is_active' => 1], 'id', 'ASC');
        if ($rules === []) {
            return 0;
        }
        $fallbackAccount = (int) (Database::scalar('SELECT id FROM accounts WHERE household_id = ? AND deleted_at IS NULL AND is_active = 1 ORDER BY sort_order, id LIMIT 1', [$householdId]) ?? 0);
        $owner = (int) (Database::scalar('SELECT owner_user_id FROM households WHERE id = ?', [$householdId]) ?? 0);
        $created = 0;
        foreach ($rules as $rule) {
            $accountId = (int) ($rule['account_id'] ?: $fallbackAccount);
            if ($accountId === 0) {
                Logger::warning('Recorrência sem conta; nada gerado', ['rule' => $rule['id']]);
                continue;
            }
            $cursor = !empty($rule['next_run_date']) ? new DateTimeImmutable((string) $rule['next_run_date']) : self::nextOccurrence($rule, new DateTimeImmutable((string) $rule['start_date']));
            if ($cursor === null) {
                continue;
            }
            // Garante que o cursor é uma ocorrência válida (proteção contra edições da regra)
            $cursor = self::nextOccurrence($rule, $cursor);
            $horizon = $today->modify('+' . max(0, (int) $rule['generate_days_ahead']) . ' days');
            $guard = 0;
            while ($cursor !== null && $cursor <= $horizon && $guard++ < 400) {
                $exists = Database::scalar('SELECT id FROM transactions WHERE household_id = ? AND recurring_id = ? AND date = ? LIMIT 1', [$householdId, (int) $rule['id'], $cursor->format('Y-m-d')]);
                if ($exists === null) {
                    self::createOccurrence($householdId, $rule, $cursor, $accountId, (int) ($rule['responsible_user_id'] ?: $owner));
                    $created++;
                }
                $cursor = self::nextOccurrence($rule, $cursor->modify('+1 day'));
            }
            RecurringRule::forHousehold($householdId)->update((int) $rule['id'], ['next_run_date' => $cursor?->format('Y-m-d'), 'is_active' => $cursor === null ? 0 : 1]);
        }
        return $created;
    }

    /** Gera para todos os lares ativos (cron). @return array{lares:int,criados:int} */
    public static function generateAll(): array
    {
        $ids = array_map('intval', array_column(Database::select("SELECT id FROM households WHERE status = 'active'"), 'id'));
        $total = 0;
        foreach ($ids as $id) {
            try {
                $total += self::generate($id);
            } catch (\Throwable $e) {
                Logger::error('Falha ao gerar recorrências', ['household' => $id, 'exception' => $e]);
            }
        }
        return ['lares' => count($ids), 'criados' => $total];
    }

    /** @param array<string,mixed> $rule */
    private static function createOccurrence(int $householdId, array $rule, DateTimeImmutable $date, int $accountId, int $createdBy): void
    {
        $amount = self::expectedAmount($householdId, $rule);
        Transaction::forHousehold($householdId)->create([
            'account_id'          => $accountId,
            'category_id'         => $rule['category_id'] !== null ? (int) $rule['category_id'] : null,
            'responsible_user_id' => $rule['responsible_user_id'] !== null ? (int) $rule['responsible_user_id'] : null,
            'created_by'          => $createdBy,
            'type'                => $rule['kind'] === 'income' ? 'income' : 'expense',
            'amount'              => number_format($amount, 2, '.', ''),
            'date'                => $date->format('Y-m-d'),
            'description'         => (string) $rule['description'],
            'status'              => 'scheduled',
            'recurring_id'        => (int) $rule['id'],
            'auto_debit'          => (int) $rule['auto_debit'],
            'import_hash'         => Transaction::importHash($date->format('Y-m-d'), (string) $amount, (string) $rule['description'], $rule['kind'] === 'income' ? 'income' : 'expense'),
        ]);
    }

    /** Valor esperado: fixo, ou média das últimas 3 ocorrências pagas (cai no fixo se ainda não houver histórico). */
    /** @param array<string,mixed> $rule */
    public static function expectedAmount(int $householdId, array $rule): float
    {
        if (($rule['expected_amount_source'] ?? 'fixed') === 'average') {
            $rows = Database::select("SELECT amount FROM transactions WHERE household_id = ? AND recurring_id = ? AND status = 'paid' AND deleted_at IS NULL ORDER BY date DESC LIMIT 3", [$householdId, (int) $rule['id']]);
            if ($rows !== []) {
                return round(array_sum(array_map('floatval', array_column($rows, 'amount'))) / count($rows), 2);
            }
        }
        return (float) $rule['expected_amount'];
    }

    /** Ocorrências agendadas (geradas por regras) até N dias à frente, mais as atrasadas, para confirmação. */
    /** @return array<int,array<string,mixed>> */
    public static function pendingOccurrences(int $householdId, DateTimeImmutable $today, int $daysAhead = 7): array
    {
        return Database::select(
            "SELECT t.*, a.name AS account_name, r.is_major_event, r.expected_amount, r.description AS rule_description
               FROM transactions t JOIN recurring_rules r ON r.id = t.recurring_id LEFT JOIN accounts a ON a.id = t.account_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.status = 'scheduled' AND t.date <= ?
              ORDER BY t.date, t.id",
            [$householdId, $today->modify("+{$daysAhead} days")->format('Y-m-d')]
        );
    }

    /** Indicador "% das contas em débito automático" por responsável (regras mensais de despesa). */
    /** @return array<int|string,array{name:string,total:int,auto:int,pct:int}> */
    public static function autoDebitStats(int $householdId): array
    {
        $rows = Database::select(
            "SELECT r.responsible_user_id, u.name, COUNT(*) AS total, SUM(r.auto_debit) AS auto
               FROM recurring_rules r LEFT JOIN users u ON u.id = r.responsible_user_id
              WHERE r.household_id = ? AND r.deleted_at IS NULL AND r.is_active = 1 AND r.kind = 'expense'
              GROUP BY r.responsible_user_id, u.name ORDER BY u.name",
            [$householdId]
        );
        $out = [];
        foreach ($rows as $r) {
            $total = (int) $r['total'];
            $out[$r['responsible_user_id'] ?? 'all'] = ['name' => $r['name'] ?? 'Todos (da casa)', 'total' => $total, 'auto' => (int) $r['auto'], 'pct' => $total > 0 ? (int) round((int) $r['auto'] / $total * 100) : 0];
        }
        return $out;
    }

    /**
     * Próximos eventos previstos grandes (is_major_event), com sugestão de destinação:
     * dívida com juros mais altos → reserva de emergência → demais metas.
     * @return array<int,array<string,mixed>>
     */
    public static function upcomingEvents(int $householdId, DateTimeImmutable $today, int $days = 120): array
    {
        $rules = RecurringRule::forHousehold($householdId)->all(['is_active' => 1, 'is_major_event' => 1], 'id', 'ASC');
        $out = [];
        foreach ($rules as $rule) {
            $next = self::nextOccurrence($rule, $today);
            if ($next === null || $next > $today->modify("+{$days} days")) {
                continue;
            }
            $rule['next_date'] = $next->format('Y-m-d');
            $rule['days_until'] = (int) $today->diff($next)->format('%a');
            $rule['suggestions'] = $rule['kind'] === 'income' ? self::allocationSuggestions($householdId, (float) $rule['expected_amount']) : [];
            $out[] = $rule;
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['next_date'], $b['next_date']));
        return $out;
    }

    /** @return list<array{title:string,detail:string,amount:float}> */
    public static function allocationSuggestions(int $householdId, float $amount): array
    {
        $suggestions = [];
        $remaining = $amount;
        // 1) Dívidas e juros: maior recorrência da categoria "Dívidas e juros" (id 13 no modelo global) ou filhas
        $debt = Database::selectOne(
            "SELECT r.description, r.expected_amount FROM recurring_rules r JOIN categories c ON c.id = r.category_id
              WHERE r.household_id = ? AND r.deleted_at IS NULL AND r.is_active = 1 AND r.kind = 'expense' AND (c.id = 13 OR c.parent_id = 13)
              ORDER BY r.expected_amount DESC LIMIT 1",
            [$householdId]
        );
        if ($debt !== null) {
            $slice = round(min($remaining, $remaining * 0.5), 2);
            $suggestions[] = ['title' => 'Quitar ou amortizar a dívida mais cara', 'detail' => $debt['description'] . ' (' . money($debt['expected_amount']) . '/mês)', 'amount' => $slice];
            $remaining -= $slice;
        }
        // 2) Reserva de emergência: meta ligada a conta de poupança/reserva ou com "reserva" no nome
        $reserve = Database::selectOne(
            "SELECT g.name, g.target_amount, g.saved_amount FROM goals g LEFT JOIN accounts a ON a.id = g.linked_account_id
              WHERE g.household_id = ? AND g.deleted_at IS NULL AND g.status = 'active' AND (a.type = 'savings' OR g.name LIKE '%reserva%')
              ORDER BY g.id LIMIT 1",
            [$householdId]
        );
        if ($reserve !== null && $remaining > 0) {
            $gap = max(0.0, (float) $reserve['target_amount'] - (float) $reserve['saved_amount']);
            $slice = round(min($remaining, $gap > 0 ? $gap : $remaining), 2);
            $suggestions[] = ['title' => 'Reforçar a reserva de emergência', 'detail' => $reserve['name'] . ' (faltam ' . money($gap) . ')', 'amount' => $slice];
            $remaining -= $slice;
        }
        // 3) Demais metas ativas
        $goals = Database::select("SELECT name, target_amount, saved_amount FROM goals WHERE household_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY deadline IS NULL, deadline, id", [$householdId]);
        foreach ($goals as $g) {
            if ($remaining <= 0) {
                break;
            }
            if ($reserve !== null && $g['name'] === $reserve['name']) {
                continue;
            }
            $gap = max(0.0, (float) $g['target_amount'] - (float) $g['saved_amount']);
            if ($gap <= 0) {
                continue;
            }
            $slice = round(min($remaining, $gap), 2);
            $suggestions[] = ['title' => 'Avançar na meta', 'detail' => $g['name'] . ' (faltam ' . money($gap) . ')', 'amount' => $slice];
            $remaining -= $slice;
        }
        if ($remaining > 0) {
            $suggestions[] = ['title' => 'Sobra livre', 'detail' => 'Sem dívidas ou metas para cobrir: guarde em investimento ou use com consciência.', 'amount' => round($remaining, 2)];
        }
        return $suggestions;
    }
}
