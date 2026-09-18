<?php
// app/Services/ReportService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Category;
use App\Models\Transaction;
use DateTimeImmutable;

/**
 * Relatórios (§6.5): mensal por categoria (com comparação ao mês anterior e ao mesmo mês do ano passado),
 * anual (12 meses), por membro, por categoria (evolução) e por conta/cartão (extrato / fatura).
 * Cada relatório devolve os dados para a tela e uma "tabela" genérica para CSV/PDF.
 */
final class ReportService
{
    /** @return array<string,mixed> */
    public static function monthly(int $householdId, string $monthStart, ?int $memberId): array
    {
        $month = new DateTimeImmutable($monthStart);
        $current = self::byCategory($householdId, $month, $memberId);
        $previous = self::byCategory($householdId, $month->modify('-1 month'), $memberId);
        $lastYear = self::byCategory($householdId, $month->modify('-1 year'), $memberId);
        $parents = [];
        foreach (['expense', 'income'] as $kind) {
            foreach ($current[$kind] as $key => $row) {
                $parents[$kind][$key] = $row + ['previous' => $previous[$kind][$key]['amount'] ?? 0.0, 'last_year' => $lastYear[$kind][$key]['amount'] ?? 0.0];
            }
            foreach ($previous[$kind] as $key => $row) {
                if (!isset($parents[$kind][$key])) {
                    $parents[$kind][$key] = ['id' => $row['id'], 'name' => $row['name'], 'color' => $row['color'], 'amount' => 0.0, 'children' => [], 'previous' => $row['amount'], 'last_year' => $lastYear[$kind][$key]['amount'] ?? 0.0];
                }
            }
        }
        $expenses = array_values($parents['expense'] ?? []);
        $incomes = array_values($parents['income'] ?? []);
        $totalExpense = array_sum(array_column($expenses, 'amount'));
        $totalIncome = array_sum(array_column($incomes, 'amount'));
        foreach ($expenses as &$e) {
            $e['pct'] = $totalExpense > 0 ? (int) round($e['amount'] / $totalExpense * 100) : 0;
            $e['delta'] = round($e['amount'] - $e['previous'], 2);
            foreach ($e['children'] as &$ch) {
                $prevChildren = $previous['expense'][self::key($e)]['children'] ?? [];
                $ch['previous'] = $prevChildren[$ch['name']]['amount'] ?? 0.0;
                $ch['delta'] = round($ch['amount'] - $ch['previous'], 2);
            }
            unset($ch);
        }
        unset($e);
        usort($expenses, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);
        $ranking = array_values(array_filter($expenses, static fn(array $e): bool => $e['delta'] > 0));
        usort($ranking, static fn(array $a, array $b): int => $b['delta'] <=> $a['delta']);
        return [
            'month'    => $month->format('Y-m-01'),
            'label'    => month_name((int) $month->format('n')) . ' de ' . $month->format('Y'),
            'expenses' => $expenses,
            'incomes'  => $incomes,
            'ranking'  => array_slice($ranking, 0, 5),
            'totals'   => [
                'expense' => round($totalExpense, 2), 'previous_expense' => round(array_sum(array_column($expenses, 'previous')), 2), 'last_year_expense' => round(array_sum(array_column($expenses, 'last_year')), 2),
                'income' => round($totalIncome, 2), 'previous_income' => round(array_sum(array_column($incomes, 'previous')), 2),
                'balance' => round($totalIncome - $totalExpense, 2),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function annual(int $householdId, int $year, ?int $memberId): array
    {
        $memberSql = $memberId !== null ? ' AND t.responsible_user_id = ?' : '';
        $params = [$householdId, "{$year}-01-01", "{$year}-12-31"];
        if ($memberId !== null) {
            $params[] = $memberId;
        }
        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $rows[sprintf('%04d-%02d', $year, $m)] = ['month' => sprintf('%04d-%02d', $year, $m), 'label' => month_name($m), 'income' => 0.0, 'expense' => 0.0];
        }
        foreach (Database::select(
            "SELECT DATE_FORMAT(t.date, '%Y-%m') AS ym, t.type, SUM(t.amount) AS total FROM transactions t
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.status <> 'scheduled' AND t.type IN ('income','expense') AND t.date BETWEEN ? AND ?{$memberSql} GROUP BY ym, t.type",
            $params
        ) as $r) {
            if (isset($rows[$r['ym']])) {
                $rows[$r['ym']][$r['type']] = round((float) $r['total'], 2);
            }
        }
        $best = null;
        $worst = null;
        foreach ($rows as &$r) {
            $r['balance'] = round($r['income'] - $r['expense'], 2);
            $r['savings_rate'] = $r['income'] > 0 ? (int) round($r['balance'] / $r['income'] * 100) : null;
            if ($r['income'] > 0 || $r['expense'] > 0) {
                $best = $best === null || $r['balance'] > $best['balance'] ? $r : $best;
                $worst = $worst === null || $r['balance'] < $worst['balance'] ? $r : $worst;
            }
        }
        unset($r);
        $income = array_sum(array_column($rows, 'income'));
        $expense = array_sum(array_column($rows, 'expense'));
        return [
            'year' => $year, 'rows' => array_values($rows), 'best' => $best, 'worst' => $worst,
            'totals' => ['income' => round($income, 2), 'expense' => round($expense, 2), 'balance' => round($income - $expense, 2), 'savings_rate' => $income > 0 ? (int) round(($income - $expense) / $income * 100) : null,
                         'avg_income' => round($income / 12, 2), 'avg_expense' => round($expense / 12, 2)],
        ];
    }

    /** @return array<string,mixed> */
    public static function members(int $householdId, string $monthStart): array
    {
        $month = new DateTimeImmutable($monthStart);
        $from = $month->format('Y-m-01');
        $to = $month->modify('last day of this month')->format('Y-m-d');
        $rows = [];
        foreach (Database::select(
            "SELECT t.responsible_user_id AS user_id, COALESCE(u.name, 'Todos (da casa)') AS name, COALESCE(u.color, '#6c757d') AS color,
                    COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount END), 0) AS income, COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0) AS expense
               FROM transactions t LEFT JOIN users u ON u.id = t.responsible_user_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.status <> 'scheduled' AND t.date BETWEEN ? AND ?
              GROUP BY t.responsible_user_id, u.name, u.color ORDER BY expense DESC",
            [$householdId, $from, $to]
        ) as $r) {
            $top = Database::select(
                "SELECT COALESCE(p.name, c.name, 'Sem categoria') AS name, SUM(t.amount) AS total FROM transactions t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN categories p ON p.id = c.parent_id
                  WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.type = 'expense' AND t.status <> 'scheduled' AND t.date BETWEEN ? AND ? AND (t.responsible_user_id <=> ?)
                  GROUP BY COALESCE(p.name, c.name, 'Sem categoria') ORDER BY total DESC LIMIT 3",
                [$householdId, $from, $to, $r['user_id']]
            );
            $rows[] = ['user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null, 'name' => (string) $r['name'], 'color' => (string) $r['color'], 'income' => round((float) $r['income'], 2), 'expense' => round((float) $r['expense'], 2), 'balance' => round((float) $r['income'] - (float) $r['expense'], 2),
                       'top' => array_map(static fn(array $t): array => ['name' => (string) $t['name'], 'amount' => round((float) $t['total'], 2)], $top)];
        }
        $total = array_sum(array_column($rows, 'expense'));
        foreach ($rows as &$r) {
            $r['share'] = $total > 0 ? (int) round($r['expense'] / $total * 100) : 0;
        }
        unset($r);
        return ['month' => $from, 'label' => month_name((int) $month->format('n')) . ' de ' . $month->format('Y'), 'rows' => $rows, 'totals' => ['income' => round(array_sum(array_column($rows, 'income')), 2), 'expense' => round($total, 2)]];
    }

    /** Evolução de uma categoria (pai inclui filhas) nos últimos N meses + lançamentos do mês escolhido. */
    /** @return array<string,mixed> */
    public static function category(int $householdId, int $categoryId, string $monthStart, int $months = 12): array
    {
        $month = new DateTimeImmutable($monthStart);
        $cat = Category::forHousehold($householdId)->map()[$categoryId] ?? null;
        $rows = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = $month->modify("-{$i} months");
            $rows[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'label' => month_name((int) $m->format('n'), true) . '/' . $m->format('y'), 'amount' => 0.0];
        }
        foreach (Database::select(
            "SELECT DATE_FORMAT(t.date, '%Y-%m') AS ym, SUM(t.amount) AS total FROM transactions t
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.status <> 'scheduled' AND t.type <> 'transfer' AND t.date BETWEEN ? AND ?
                AND (t.category_id = ? OR t.category_id IN (SELECT id FROM categories WHERE parent_id = ?)) GROUP BY ym",
            [$householdId, $month->modify('-' . ($months - 1) . ' months')->format('Y-m-01'), $month->modify('last day of this month')->format('Y-m-d'), $categoryId, $categoryId]
        ) as $r) {
            if (isset($rows[$r['ym']])) {
                $rows[$r['ym']]['amount'] = round((float) $r['total'], 2);
            }
        }
        $model = new Transaction();
        $transactions = array_map(static fn(array $r): array => TransactionPolicy::mask($model->castRow($r)), Database::select(
            'SELECT t.*, a.name AS account_name, u.name AS responsible_name, c.name AS category_name FROM transactions t JOIN accounts a ON a.id = t.account_id LEFT JOIN users u ON u.id = t.responsible_user_id LEFT JOIN categories c ON c.id = t.category_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.status <> \'scheduled\' AND t.date BETWEEN ? AND ? AND (t.category_id = ? OR t.category_id IN (SELECT id FROM categories WHERE parent_id = ?)) ORDER BY t.date DESC, t.id DESC LIMIT 200',
            [$householdId, $month->format('Y-m-01'), $month->modify('last day of this month')->format('Y-m-d'), $categoryId, $categoryId]
        ));
        $values = array_column($rows, 'amount');
        $nonZero = array_filter($values, static fn(float $v): bool => $v > 0);
        return [
            'category' => $cat, 'month' => $month->format('Y-m-01'), 'label' => month_name((int) $month->format('n')) . ' de ' . $month->format('Y'),
            'rows' => array_values($rows), 'total' => round(array_sum($values), 2), 'average' => $nonZero !== [] ? round(array_sum($nonZero) / count($nonZero), 2) : 0.0,
            'max' => $values !== [] ? max($values) : 0.0, 'transactions' => $transactions,
        ];
    }

    /** Extrato de conta (saldo inicial → final) ou fatura de cartão (período de fechamento). */
    /** @return array<string,mixed>|null */
    public static function account(int $householdId, int $accountId, string $monthStart): ?array
    {
        $account = Database::selectOne('SELECT * FROM accounts WHERE id = ? AND household_id = ? AND deleted_at IS NULL', [$accountId, $householdId]);
        if ($account === null) {
            return null;
        }
        $month = new DateTimeImmutable($monthStart);
        $isCard = $account['type'] === 'credit_card';
        if ($isCard && !empty($account['closing_day'])) {
            $closing = RecurrenceService::clampDay($month, (int) $account['closing_day']);
            $from = RecurrenceService::clampDay($month->modify('-1 month'), (int) $account['closing_day'])->modify('+1 day');
            $to = $closing;
            $due = !empty($account['due_day']) ? RecurrenceService::clampDay((int) $account['due_day'] > (int) $account['closing_day'] ? $month : $month->modify('+1 month'), (int) $account['due_day']) : null;
        } else {
            $from = $month->modify('first day of this month');
            $to = $month->modify('last day of this month');
            $due = null;
        }
        $opening = (float) $account['initial_balance'] + (float) Database::scalar(
            "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount WHEN type = 'expense' THEN -amount WHEN transfer_account_id = ? THEN amount ELSE -amount END), 0)
               FROM transactions WHERE household_id = ? AND deleted_at IS NULL AND status <> 'scheduled' AND date < ? AND (account_id = ? OR transfer_account_id = ?)",
            [$accountId, $householdId, $from->format('Y-m-d'), $accountId, $accountId]
        );
        $model = new Transaction();
        $rows = [];
        $balance = $opening;
        $charges = 0.0;
        $payments = 0.0;
        foreach (Database::select(
            'SELECT t.*, u.name AS responsible_name, c.name AS category_name, ta.name AS transfer_account_name, a.name AS account_name FROM transactions t JOIN accounts a ON a.id = t.account_id LEFT JOIN users u ON u.id = t.responsible_user_id LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN accounts ta ON ta.id = t.transfer_account_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.status <> \'scheduled\' AND t.date BETWEEN ? AND ? AND (t.account_id = ? OR t.transfer_account_id = ?) ORDER BY t.date, t.id',
            [$householdId, $from->format('Y-m-d'), $to->format('Y-m-d'), $accountId, $accountId]
        ) as $r) {
            $r = TransactionPolicy::mask($model->castRow($r));
            $delta = match (true) {
                $r['type'] === 'income' => (float) $r['amount'],
                $r['type'] === 'expense' => -(float) $r['amount'],
                (int) $r['transfer_account_id'] === $accountId => (float) $r['amount'],
                default => -(float) $r['amount'],
            };
            $balance += $delta;
            if ($delta < 0) {
                $charges += -$delta;
            } else {
                $payments += $delta;
            }
            $r['delta'] = round($delta, 2);
            $r['running'] = round($balance, 2);
            $rows[] = $r;
        }
        return [
            'account' => $account, 'is_card' => $isCard, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'due' => $due?->format('Y-m-d'),
            'label' => $isCard ? 'Fatura com fechamento em ' . date_br($to->format('Y-m-d')) : month_name((int) $month->format('n')) . ' de ' . $month->format('Y'),
            'opening' => round($opening, 2), 'closing' => round($balance, 2), 'charges' => round($charges, 2), 'payments' => round($payments, 2), 'rows' => $rows,
        ];
    }

    /**
     * Tabela genérica (cabeçalhos + linhas de texto) para CSV e PDF.
     * @param array<string,mixed> $data
     * @return array{headers:list<string>,rows:list<list<string>>,widths:list<float>,aligns:list<string>,bold:list<int>}
     */
    public static function toTable(string $type, array $data): array
    {
        $m = static fn(mixed $v): string => money($v, false);
        switch ($type) {
            case 'monthly':
                $rows = [];
                foreach ($data['expenses'] as $e) {
                    $rows[] = [$e['name'], $m($e['amount']), $e['pct'] . '%', $m($e['previous']), ($e['delta'] >= 0 ? '+' : '') . $m($e['delta']), $m($e['last_year'])];
                    foreach ($e['children'] as $ch) {
                        $rows[] = ['   › ' . $ch['name'], $m($ch['amount']), '', $m($ch['previous']), ($ch['delta'] >= 0 ? '+' : '') . $m($ch['delta']), ''];
                    }
                }
                $t = $data['totals'];
                $rows[] = ['Total de despesas', $m($t['expense']), '100%', $m($t['previous_expense']), ($t['expense'] - $t['previous_expense'] >= 0 ? '+' : '') . $m($t['expense'] - $t['previous_expense']), $m($t['last_year_expense'])];
                $rows[] = ['Total de receitas', $m($t['income']), '', $m($t['previous_income']), '', ''];
                $rows[] = ['Saldo', $m($t['balance']), '', $m($t['previous_income'] - $t['previous_expense']), '', ''];
                return ['headers' => ['Categoria', 'Este mês', '%', 'Mês anterior', 'Variação', 'Mesmo mês/ano passado'], 'rows' => $rows, 'widths' => [0.34, 0.14, 0.07, 0.15, 0.13, 0.17], 'aligns' => ['L', 'R', 'R', 'R', 'R', 'R'], 'bold' => [count($rows) - 3, count($rows) - 2, count($rows) - 1]];
            case 'annual':
                $rows = array_map(static fn(array $r): array => [$r['label'], $m($r['income']), $m($r['expense']), $m($r['balance']), $r['savings_rate'] === null ? '—' : $r['savings_rate'] . '%'], $data['rows']);
                $t = $data['totals'];
                $rows[] = ['Total ' . $data['year'], $m($t['income']), $m($t['expense']), $m($t['balance']), $t['savings_rate'] === null ? '—' : $t['savings_rate'] . '%'];
                $rows[] = ['Média mensal', $m($t['avg_income']), $m($t['avg_expense']), $m($t['avg_income'] - $t['avg_expense']), ''];
                return ['headers' => ['Mês', 'Receitas', 'Despesas', 'Saldo', 'Poupança'], 'rows' => $rows, 'widths' => [0.28, 0.18, 0.18, 0.18, 0.18], 'aligns' => ['L', 'R', 'R', 'R', 'R'], 'bold' => [12, 13]];
            case 'members':
                $rows = array_map(static fn(array $r): array => [$r['name'], $m($r['income']), $m($r['expense']), $r['share'] . '%', $m($r['balance']), implode(', ', array_map(static fn(array $t): string => $t['name'] . ' ' . money($t['amount'], false), $r['top']))], $data['rows']);
                $rows[] = ['Total', $m($data['totals']['income']), $m($data['totals']['expense']), '100%', $m($data['totals']['income'] - $data['totals']['expense']), ''];
                return ['headers' => ['Membro', 'Receitas', 'Despesas', 'Parte', 'Saldo', 'Maiores categorias'], 'rows' => $rows, 'widths' => [0.18, 0.13, 0.13, 0.08, 0.13, 0.35], 'aligns' => ['L', 'R', 'R', 'R', 'R', 'L'], 'bold' => [count($rows) - 1]];
            case 'category':
                $rows = array_map(static fn(array $r): array => [$r['label'], $m($r['amount'])], $data['rows']);
                $rows[] = ['Média (meses com gasto)', $m($data['average'])];
                $rows[] = ['Total do período', $m($data['total'])];
                return ['headers' => ['Mês', 'Valor'], 'rows' => $rows, 'widths' => [0.6, 0.4], 'aligns' => ['L', 'R'], 'bold' => [count($rows) - 2, count($rows) - 1]];
            default: // account
                $rows = [];
                $rows[] = ['', 'Saldo inicial', '', '', $m($data['opening'])];
                foreach ($data['rows'] as $r) {
                    $rows[] = [date_br($r['date']), $r['description'], $r['category_name'] ?? ($r['type'] === 'transfer' ? 'Transferência' : ''), ($r['delta'] >= 0 ? '+' : '') . $m($r['delta']), $m($r['running'])];
                }
                $rows[] = ['', $data['is_card'] ? 'Total da fatura' : 'Saldo final', '', '', $m($data['is_card'] ? $data['charges'] - $data['payments'] : $data['closing'])];
                return ['headers' => ['Data', 'Descrição', 'Categoria', 'Valor', 'Saldo'], 'rows' => $rows, 'widths' => [0.12, 0.38, 0.22, 0.14, 0.14], 'aligns' => ['L', 'L', 'L', 'R', 'R'], 'bold' => [0, count($rows) - 1]];
        }
    }

    /** CSV para Excel pt-BR: BOM UTF-8, ponto e vírgula, aspas quando preciso. */
    /** @param array{headers:list<string>,rows:list<list<string>>} $table */
    public static function csv(array $table): string
    {
        $out = "\xEF\xBB\xBF";
        $line = static function (array $cells): string {
            return implode(';', array_map(static function (string $c): string {
                $c = trim($c);
                return preg_match('/[;"\r\n]/', $c) ? '"' . str_replace('"', '""', $c) . '"' : $c;
            }, $cells)) . "\r\n";
        };
        $out .= $line($table['headers']);
        foreach ($table['rows'] as $row) {
            $out .= $line(array_map('strval', $row));
        }
        return $out;
    }

    // --- internos ---

    /** Gastos e receitas do mês agrupados por categoria pai, com filhas. @return array{expense:array<string,array<string,mixed>>,income:array<string,array<string,mixed>>} */
    private static function byCategory(int $householdId, DateTimeImmutable $month, ?int $memberId): array
    {
        $memberSql = $memberId !== null ? ' AND t.responsible_user_id = ?' : '';
        $params = [$householdId, $month->format('Y-m-01'), $month->modify('last day of this month')->format('Y-m-d')];
        if ($memberId !== null) {
            $params[] = $memberId;
        }
        $out = ['expense' => [], 'income' => []];
        foreach (Database::select(
            "SELECT t.type, COALESCE(p.id, c.id) AS parent_id, COALESCE(p.name, c.name, 'Sem categoria') AS parent_name, COALESCE(p.color, c.color) AS color,
                    CASE WHEN p.id IS NULL THEN NULL ELSE c.name END AS child_name, SUM(t.amount) AS total
               FROM transactions t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN categories p ON p.id = c.parent_id
              WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.status <> 'scheduled' AND t.type IN ('income','expense') AND t.date BETWEEN ? AND ?{$memberSql}
              GROUP BY t.type, COALESCE(p.id, c.id), COALESCE(p.name, c.name, 'Sem categoria'), COALESCE(p.color, c.color), CASE WHEN p.id IS NULL THEN NULL ELSE c.name END
              ORDER BY total DESC",
            $params
        ) as $r) {
            $key = ($r['parent_id'] ?? '0') . '|' . $r['parent_name'];
            if (!isset($out[$r['type']][$key])) {
                $out[$r['type']][$key] = ['id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null, 'name' => (string) $r['parent_name'], 'color' => $r['color'] ?: '#94a3b8', 'amount' => 0.0, 'children' => []];
            }
            $out[$r['type']][$key]['amount'] = round($out[$r['type']][$key]['amount'] + (float) $r['total'], 2);
            if ($r['child_name'] !== null) {
                $out[$r['type']][$key]['children'][(string) $r['child_name']] = ['name' => (string) $r['child_name'], 'amount' => round((float) $r['total'], 2)];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function key(array $row): string
    {
        return ($row['id'] ?? '0') . '|' . $row['name'];
    }
}
