<?php
// app/Services/CashflowService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;

/**
 * Previsão de caixa dia a dia (plano A, item 1): parte do saldo líquido de hoje e aplica, dia a dia, os lançamentos
 * pendentes/agendados, as parcelas futuras e as ocorrências das recorrências ainda não geradas.
 * Devolve a curva, o primeiro dia negativo, o ponto mais baixo e a "sobra segura" (quanto dá para aplicar hoje).
 */
final class CashflowService
{
    /** @return array{start:float,days:list<array{date:string,in:float,out:float,balance:float,items:list<string>}>,first_negative:?string,lowest:array{date:string,balance:float},safe_surplus:float,next_income:?string} */
    public static function forecast(int $householdId, DateTimeImmutable $today, int $days = 60): array
    {
        $liquid = 0.0;
        foreach (AccountService::withBalances($householdId, true) as $a) {
            if (in_array($a['type'], ['checking', 'cash'], true)) {
                $liquid += (float) $a['balance'];
            }
        }
        $end = $today->modify("+{$days} days");
        $byDay = [];
        // 1) Lançamentos ainda não pagos (pendentes/agendados), inclusive vencidos (contam hoje)
        foreach (Database::select(
            "SELECT t.date, t.type, t.amount, t.description, a.type AS account_type, ta.type AS transfer_type
               FROM transactions t JOIN accounts a ON a.id = t.account_id LEFT JOIN accounts ta ON ta.id = t.transfer_account_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.status <> 'paid' AND t.date <= ?",
            [$householdId, $end->format('Y-m-d')]
        ) as $t) {
            $date = max((string) $t['date'], $today->format('Y-m-d'));
            self::apply($byDay, $date, (string) $t['type'], (float) $t['amount'], (string) $t['description'], (string) $t['account_type'], $t['transfer_type']);
        }
        // 2) Ocorrências futuras de recorrências ainda não geradas (além do horizonte de geração)
        foreach (Database::select("SELECT r.*, a.type AS account_type FROM recurring_rules r LEFT JOIN accounts a ON a.id = r.account_id WHERE r.household_id = ? AND r.deleted_at IS NULL AND r.is_active = 1", [$householdId]) as $rule) {
            $cursor = !empty($rule['next_run_date']) ? new DateTimeImmutable((string) $rule['next_run_date']) : null;
            $guard = 0;
            while ($cursor !== null && $cursor <= $end && $guard++ < 100) {
                $exists = Database::scalar('SELECT id FROM transactions WHERE household_id = ? AND recurring_id = ? AND date = ?', [$householdId, (int) $rule['id'], $cursor->format('Y-m-d')]);
                if ($exists === null && $cursor >= $today) {
                    self::apply($byDay, $cursor->format('Y-m-d'), $rule['kind'] === 'income' ? 'income' : 'expense', RecurrenceService::expectedAmount($householdId, $rule), (string) $rule['description'], (string) ($rule['account_type'] ?? 'checking'), null);
                }
                $cursor = RecurrenceService::nextOccurrence($rule, $cursor->modify('+1 day'));
            }
        }
        // 3) Faturas de cartão: o que está lançado no cartão sai da conta no vencimento
        foreach (Database::select("SELECT id, name, closing_day, due_day FROM accounts WHERE household_id = ? AND deleted_at IS NULL AND is_active = 1 AND type = 'credit_card' AND due_day IS NOT NULL", [$householdId]) as $card) {
            for ($m = 0; $m <= 2; $m++) {
                $month = $today->modify('first day of this month')->modify("+{$m} months");
                $due = RecurrenceService::clampDay($month, (int) $card['due_day']);
                if ($due < $today || $due > $end) {
                    continue;
                }
                $closing = RecurrenceService::clampDay((int) $card['due_day'] > (int) ($card['closing_day'] ?: 1) ? $month : $month->modify('-1 month'), (int) ($card['closing_day'] ?: 1));
                $from = RecurrenceService::clampDay($closing->modify('-1 month'), (int) ($card['closing_day'] ?: 1))->modify('+1 day');
                $total = (float) Database::scalar("SELECT COALESCE(SUM(CASE WHEN type = 'expense' THEN amount WHEN type = 'income' THEN -amount ELSE 0 END), 0) FROM transactions WHERE household_id = ? AND account_id = ? AND deleted_at IS NULL AND date BETWEEN ? AND ?", [$householdId, (int) $card['id'], $from->format('Y-m-d'), $closing->format('Y-m-d')]);
                if ($total > 0) {
                    $key = $due->format('Y-m-d');
                    $byDay[$key]['out'] = ($byDay[$key]['out'] ?? 0) + $total;
                    $byDay[$key]['items'][] = 'Fatura ' . $card['name'] . ' ' . money($total);
                }
            }
        }
        // 4) Curva dia a dia
        $balance = $liquid;
        $curve = [];
        $firstNegative = null;
        $lowest = ['date' => $today->format('Y-m-d'), 'balance' => $balance];
        $nextIncome = null;
        for ($i = 0; $i <= $days; $i++) {
            $d = $today->modify("+{$i} days")->format('Y-m-d');
            $in = (float) ($byDay[$d]['in'] ?? 0);
            $out = (float) ($byDay[$d]['out'] ?? 0);
            $balance += $in - $out;
            if ($in > 0 && $nextIncome === null && $i > 0) {
                $nextIncome = $d;
            }
            if ($balance < 0 && $firstNegative === null) {
                $firstNegative = $d;
            }
            if ($balance < $lowest['balance']) {
                $lowest = ['date' => $d, 'balance' => round($balance, 2)];
            }
            $curve[] = ['date' => $d, 'in' => round($in, 2), 'out' => round($out, 2), 'balance' => round($balance, 2), 'items' => $byDay[$d]['items'] ?? []];
        }
        // Sobra segura: o mínimo da curva até o próximo salário (ou até o fim do horizonte), nunca negativo
        $untilIncome = $nextIncome ?? $end->format('Y-m-d');
        $minUntil = $liquid;
        foreach ($curve as $point) {
            if ($point['date'] > $untilIncome) {
                break;
            }
            $minUntil = min($minUntil, $point['balance']);
        }
        return ['start' => round($liquid, 2), 'days' => $curve, 'first_negative' => $firstNegative, 'lowest' => $lowest, 'safe_surplus' => round(max(0.0, $minUntil), 2), 'next_income' => $nextIncome];
    }

    /** @param array<string,array<string,mixed>> $byDay */
    private static function apply(array &$byDay, string $date, string $type, float $amount, string $description, string $accountType, ?string $transferType): void
    {
        $liquidTypes = ['checking', 'cash'];
        if ($type === 'income' && in_array($accountType, $liquidTypes, true)) {
            $byDay[$date]['in'] = ($byDay[$date]['in'] ?? 0) + $amount;
            $byDay[$date]['items'][] = '+ ' . $description . ' ' . money($amount);
        } elseif ($type === 'expense' && in_array($accountType, $liquidTypes, true)) {
            $byDay[$date]['out'] = ($byDay[$date]['out'] ?? 0) + $amount;
            $byDay[$date]['items'][] = '− ' . $description . ' ' . money($amount);
        } elseif ($type === 'transfer') {
            $fromLiquid = in_array($accountType, $liquidTypes, true);
            $toLiquid = in_array((string) $transferType, $liquidTypes, true);
            if ($fromLiquid && !$toLiquid) {
                $byDay[$date]['out'] = ($byDay[$date]['out'] ?? 0) + $amount;
                $byDay[$date]['items'][] = '− ' . $description . ' ' . money($amount);
            } elseif (!$fromLiquid && $toLiquid) {
                $byDay[$date]['in'] = ($byDay[$date]['in'] ?? 0) + $amount;
                $byDay[$date]['items'][] = '+ ' . $description . ' ' . money($amount);
            }
        }
        // despesas no cartão entram pela fatura (passo 3)
    }
}
