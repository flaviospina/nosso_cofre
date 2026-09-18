<?php
// tests/AccountServiceTest.php — saldos atual/projetado por conta e totais (integração: banco do .env; pulados se indisponível)
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

use App\Core\Database;
use App\Services\AccountService;

function test_account_balances_consider_status_and_transfers(): void
{
    if (!db_available('category_rules')) { return; }
    $f = privacy_fixture();
    $h = (int) $f['household'];
    $now = gmdate('Y-m-d H:i:s');
    $by = (int) $f['owner'];
    Database::execute('UPDATE accounts SET initial_balance = 100 WHERE id = ?', [(int) $f['account']]);
    $card = Database::insert('accounts', ['household_id' => $h, 'name' => 'Cartão', 'type' => 'credit_card', 'limit_amount' => '1000.00', 'created_at' => $now]);
    // fixture: 10,00 paga na conta + 20,00 paga na conjunta
    Database::insert('transactions', ['household_id' => $h, 'account_id' => (int) $f['account'], 'created_by' => $by, 'type' => 'income', 'amount' => '50.00', 'date' => '2026-09-03', 'description' => 'Pix', 'status' => 'paid', 'created_at' => $now]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => (int) $f['account'], 'created_by' => $by, 'type' => 'expense', 'amount' => '30.00', 'date' => '2026-09-20', 'description' => 'Luz', 'status' => 'pending', 'created_at' => $now]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => (int) $f['account'], 'transfer_account_id' => (int) $f['joint'], 'created_by' => $by, 'type' => 'transfer', 'amount' => '25.00', 'date' => '2026-09-04', 'description' => 'Transferência', 'status' => 'paid', 'created_at' => $now]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => $card, 'created_by' => $by, 'type' => 'expense', 'amount' => '200.00', 'date' => '2026-09-05', 'description' => 'Compra', 'status' => 'pending', 'created_at' => $now]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => (int) $f['account'], 'created_by' => $by, 'type' => 'expense', 'amount' => '999.00', 'date' => '2026-09-06', 'description' => 'Apagada', 'status' => 'paid', 'created_at' => $now, 'deleted_at' => $now]);

    $accounts = AccountService::withBalances($h);
    $byId = [];
    foreach ($accounts as $a) { $byId[(int) $a['id']] = $a; }
    $acc = $byId[(int) $f['account']];
    assert_same(115.0, round($acc['balance'], 2), 'saldo atual: 100 + 50 − 10 − 25 (pendente e apagado ficam de fora)');
    assert_same(85.0, round($acc['projected'], 2), 'projetado inclui a pendente de 30');
    assert_same(5.0, round($byId[(int) $f['joint']]['balance'], 2), 'conjunta: −20 + 25 da transferência');
    assert_same(-200.0, round($byId[$card]['projected'], 2), 'cartão: fatura em aberto');
    $totals = AccountService::totals($accounts);
    assert_same(120.0, round($totals['cash'], 2));
    assert_same(200.0, round($totals['cards'], 2));
    assert_same(-80.0, round($totals['net'], 2));
}
