<?php
// tests/CashflowTest.php — previsão de caixa dia a dia (integração)
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

use App\Core\Database;
use App\Services\CashflowService;

function test_cashflow_forecast_finds_negative_day_and_safe_surplus(): void
{
    if (!db_available('recurring_rules')) { return; }
    $f = privacy_fixture();
    $h = (int) $f['household']; $acc = (int) $f['account']; $by = (int) $f['owner'];
    $ts = gmdate('Y-m-d H:i:s');
    $today = new DateTimeImmutable('2026-09-18');
    Database::execute('UPDATE accounts SET initial_balance = 530 WHERE id = ?', [$acc]);   // saldo líquido hoje: 530 − 10 (conta) − 20 (conjunta em dinheiro, fixture) = 500
    Database::insert('transactions', ['household_id' => $h, 'account_id' => $acc, 'created_by' => $by, 'type' => 'expense', 'amount' => '400.00', 'date' => '2026-09-20', 'description' => 'Aluguel', 'status' => 'pending', 'created_at' => $ts]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => $acc, 'created_by' => $by, 'type' => 'expense', 'amount' => '200.00', 'date' => '2026-09-25', 'description' => 'Energia', 'status' => 'scheduled', 'created_at' => $ts]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => $acc, 'created_by' => $by, 'type' => 'income', 'amount' => '3000.00', 'date' => '2026-10-05', 'description' => 'Salário', 'status' => 'scheduled', 'created_at' => $ts]);
    Database::insert('recurring_rules', ['household_id' => $h, 'description' => 'Internet', 'kind' => 'expense', 'account_id' => $acc, 'expected_amount' => '100.00', 'frequency' => 'monthly', 'day_of_month' => 12, 'start_date' => '2026-01-01', 'next_run_date' => '2026-10-12', 'is_active' => 1, 'created_at' => $ts]);
    $card = Database::insert('accounts', ['household_id' => $h, 'name' => 'Cartão', 'type' => 'credit_card', 'closing_day' => 10, 'due_day' => 17, 'created_at' => $ts]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => $card, 'created_by' => $by, 'type' => 'expense', 'amount' => '150.00', 'date' => '2026-09-15', 'description' => 'Compra', 'status' => 'pending', 'created_at' => $ts]);
    $fc = CashflowService::forecast($h, $today, 60);
    assert_same(500.0, $fc['start']);
    $byDate = [];
    foreach ($fc['days'] as $d) { $byDate[$d['date']] = $d; }
    assert_same(100.0, $byDate['2026-09-20']['balance'], '500 − 400');
    assert_same(-100.0, $byDate['2026-09-25']['balance'], 'agendada de 200 leva ao negativo');
    assert_same('2026-09-25', $fc['first_negative']);
    assert_same(2900.0, $byDate['2026-10-05']['balance'], 'salário entra');
    assert_same(2800.0, $byDate['2026-10-12']['balance'], 'recorrência ainda não gerada entra na previsão');
    assert_same(2650.0, $byDate['2026-10-17']['balance'], 'fatura do cartão (150, fechamento 10/10) sai no vencimento 17/10');
    assert_same('2026-10-05', $fc['next_income']);
    assert_same(0.0, $fc['safe_surplus'], 'não dá para guardar nada: fica negativo antes do salário');
    Database::execute('UPDATE accounts SET initial_balance = 1030 WHERE id = ?', [$acc]);   // +500
    $fc = CashflowService::forecast($h, $today, 60);
    assert_null($fc['first_negative']);
    assert_same(400.0, $fc['safe_surplus'], 'mínimo até o salário: 1000 − 400 − 200 = 400');
}
