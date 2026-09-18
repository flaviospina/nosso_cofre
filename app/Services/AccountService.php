<?php
// app/Services/AccountService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Account;

/**
 * Saldos por conta: saldo inicial + receitas pagas − despesas pagas ± transferências pagas.
 * Pendentes/agendados entram só no "projetado".
 */
final class AccountService
{
    /** @return array<int,array<string,mixed>> contas ativas com saldo atual e projetado */
    public static function withBalances(int $householdId, bool $onlyActive = true): array
    {
        $accounts = Account::forHousehold($householdId)->all($onlyActive ? ['is_active' => 1] : [], 'sort_order', 'ASC');
        $sums = Database::select(
            "SELECT account_id, transfer_account_id, type, status, SUM(amount) AS total
               FROM transactions WHERE household_id = ? AND deleted_at IS NULL
              GROUP BY account_id, transfer_account_id, type, status",
            [$householdId]
        );
        $balance = [];
        $projected = [];
        foreach ($accounts as $a) {
            $balance[(int) $a['id']] = (float) $a['initial_balance'];
            $projected[(int) $a['id']] = (float) $a['initial_balance'];
        }
        foreach ($sums as $s) {
            $from = (int) $s['account_id'];
            $to = (int) ($s['transfer_account_id'] ?? 0);
            $total = (float) $s['total'];
            $paid = $s['status'] === 'paid';
            $apply = static function (int $account, float $delta) use (&$balance, &$projected, $paid): void {
                if (!isset($projected[$account])) {
                    return;
                }
                $projected[$account] += $delta;
                if ($paid) {
                    $balance[$account] += $delta;
                }
            };
            if ($s['type'] === 'income') {
                $apply($from, $total);
            } elseif ($s['type'] === 'expense') {
                $apply($from, -$total);
            } else {
                $apply($from, -$total);
                if ($to > 0) {
                    $apply($to, $total);
                }
            }
        }
        foreach ($accounts as &$a) {
            $a['balance'] = $balance[(int) $a['id']] ?? 0.0;
            $a['projected'] = $projected[(int) $a['id']] ?? 0.0;
            $a['type_label'] = Account::TYPES[$a['type']] ?? $a['type'];
        }
        unset($a);
        return $accounts;
    }

    /** Somatórios do lar: saldo total (sem cartões), dívida em cartões. */
    /** @param array<int,array<string,mixed>> $accounts */
    public static function totals(array $accounts): array
    {
        $cash = 0.0;
        $cards = 0.0;
        foreach ($accounts as $a) {
            if ($a['type'] === 'credit_card') {
                // Fatura em aberto: tudo o que foi lançado no cartão (pago ou pendente), como valor devido (positivo)
                $cards += -(float) $a['projected'];
            } else {
                $cash += (float) $a['balance'];
            }
        }
        return ['cash' => $cash, 'cards' => $cards, 'net' => $cash - $cards];
    }
}
