<?php
// tests/BudgetTest.php — orçamento (gasto, projeção, cópia), essencial × supérfluo, metas e plano de ação (integração)
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

use App\Core\Database;
use App\Services\BudgetService;
use App\Services\GoalService;
use App\Services\SavingsActionService;

function budget_tx(int $h, int $account, int $by, string $date, string $amount, int $category, string $type = 'expense', string $status = 'paid'): int
{
    return Database::insert('transactions', ['household_id' => $h, 'account_id' => $account, 'created_by' => $by, 'responsible_user_id' => $by, 'type' => $type, 'amount' => $amount, 'date' => $date, 'description' => 'Teste', 'status' => $status, 'category_id' => $category, 'created_at' => gmdate('Y-m-d H:i:s')]);
}

function test_budget_overview_projection_and_status(): void
{
    if (!db_available('budgets')) { return; }
    $f = privacy_fixture();
    $h = (int) $f['household']; $acc = (int) $f['account']; $by = (int) $f['owner'];
    $today = new DateTimeImmutable('2026-09-10');   // dia 10 de 30
    // Padaria (111, filha de Alimentação 2): 150 em 10 dias → projeção 450 > limite 400 → estoura no dia 27
    budget_tx($h, $acc, $by, '2026-09-03', '100.00', 111);
    budget_tx($h, $acc, $by, '2026-09-08', '50.00', 111, 'expense', 'pending');
    budget_tx($h, $acc, $by, '2026-09-09', '999.00', 111, 'expense', 'scheduled');   // agendado não conta
    Database::insert('budgets', ['household_id' => $h, 'category_id' => 111, 'period_month' => '2026-09-01', 'limit_amount' => '400.00', 'created_at' => gmdate('Y-m-d H:i:s')]);
    // Alimentação (pai 2) com limite 1200: soma filhas (150) + gasto direto no pai (200) = 350 → projeção 1050 < 1200 → ok
    budget_tx($h, $acc, $by, '2026-09-05', '200.00', 2);
    Database::insert('budgets', ['household_id' => $h, 'category_id' => 2, 'period_month' => '2026-09-01', 'limit_amount' => '1200.00', 'alert_thresholds' => '[50,100]', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $ov = BudgetService::overview($h, '2026-09-01', $today);
    $byCat = [];
    foreach ($ov as $b) { $byCat[(int) $b['category_id']] = $b; }
    assert_same(150.0, $byCat[111]['spent']);
    assert_same(38, $byCat[111]['pct']);
    assert_same(450.0, $byCat[111]['projected']);
    assert_same(27, $byCat[111]['burst_day'], 'neste ritmo estoura no dia 27');
    assert_same('risk', $byCat[111]['status']);
    assert_same(350.0, $byCat[2]['spent'], 'pai soma as filhas');
    assert_same('ok', $byCat[2]['status']);
    assert_same([50, 100], $byCat[2]['thresholds']);
    assert_same(12.5, $byCat[111]['daily_allow']);
    // Cópia para o mês seguinte
    assert_same(2, BudgetService::copyFromPrevious($h, '2026-10-01'));
    assert_same(0, BudgetService::copyFromPrevious($h, '2026-10-01'), 'não duplica');
    assert_same(2, count(BudgetService::overview($h, '2026-10-01', $today)));
    assert_same(['2026-10-01', '2026-09-01'], BudgetService::months($h));
}

function test_budget_essential_split_and_503020(): void
{
    if (!db_available('budgets')) { return; }
    $f = privacy_fixture();
    $h = (int) $f['household']; $acc = (int) $f['account']; $by = (int) $f['owner'];
    budget_tx($h, $acc, $by, '2026-09-01', '5000.00', 19, 'income');
    budget_tx($h, $acc, $by, '2026-09-02', '2000.00', 100);   // Aluguel (essencial)
    budget_tx($h, $acc, $by, '2026-09-03', '1000.00', 181);   // Cinema (supérfluo)
    // fixture já tem 30,00 sem categoria (supérfluo)
    $s = BudgetService::essentialSplit($h, '2026-09-01');
    assert_same(5000.0, $s['income']);
    assert_same(2000.0, $s['essential']);
    assert_same(1030.0, $s['superfluous']);
    assert_same(40, $s['needs_pct']);
    assert_same(21, $s['wants_pct']);
    assert_same(39, $s['savings_pct']);
    assert_same('green', $s['light']);
    assert_same(34, $s['superfluous_share']);
}

function test_goal_progress_contribution_and_achievement(): void
{
    if (!db_available('goal_contributions')) { return; }
    $f = privacy_fixture();
    $h = (int) $f['household'];
    $today = new DateTimeImmutable('2026-09-18');
    $goal = Database::insert('goals', ['household_id' => $h, 'name' => 'Viagem', 'target_amount' => '1000.00', 'saved_amount' => '0.00', 'deadline' => '2027-03-18', 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $g = GoalService::withProgress($h, $today)[0];
    assert_same(6, $g['months_left']);
    assert_same(round(1000 / 6, 2), $g['suggested_monthly']);
    $r = GoalService::contribute($h, $goal, 600.0, '2026-09-18', 'primeiro', (int) $f['owner']);
    assert_false($r['achieved']);
    GoalService::contribute($h, $goal, -100.0, '2026-09-19', 'retirada', (int) $f['owner']);
    $r = GoalService::contribute($h, $goal, 500.0, '2026-09-20', null, (int) $f['owner']);
    assert_true($r['achieved'], '600 − 100 + 500 = 1000 bate a meta');
    $row = Database::selectOne('SELECT saved_amount, status, achieved_at FROM goals WHERE id = ?', [$goal]);
    assert_same('1000.00', $row['saved_amount']);
    assert_same('done', $row['status']);
    assert_true($row['achieved_at'] !== null);
    assert_same(3, count(GoalService::contributions($h, $goal)));
    // Meta ligada a conta usa o saldo da conta
    $linked = Database::insert('goals', ['household_id' => $h, 'name' => 'Reserva', 'target_amount' => '100.00', 'saved_amount' => '0.00', 'linked_account_id' => (int) $f['joint'], 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s')]);
    Database::execute('UPDATE accounts SET initial_balance = 80 WHERE id = ?', [(int) $f['joint']]);   // fixture: −20 pago na conjunta → saldo 60
    $goals = GoalService::withProgress($h, $today);
    $linkedGoal = array_values(array_filter($goals, static fn(array $x): bool => (int) $x['id'] === $linked))[0];
    assert_same(60.0, $linkedGoal['saved']);
    assert_same(60, $linkedGoal['pct']);
}

function test_savings_action_measures_against_baseline(): void
{
    if (!db_available('savings_actions')) { return; }
    $f = privacy_fixture();
    $h = (int) $f['household']; $acc = (int) $f['account']; $by = (int) $f['owner'];
    $today = new DateTimeImmutable('2026-09-18');
    foreach (['2026-06-10' => '300.00', '2026-07-10' => '360.00', '2026-08-10' => '240.00'] as $d => $a) {
        budget_tx($h, $acc, $by, $d, $a, 111);   // média 300
    }
    budget_tx($h, $acc, $by, '2026-09-05', '120.00', 111);
    $id = Database::insert('savings_actions', ['household_id' => $h, 'title' => 'Menos padaria', 'category_id' => 111, 'status' => 'todo', 'estimated_saving_month' => '150.00', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $ov = SavingsActionService::overview($h, $today);
    assert_null($ov['items'][0]['measured'], 'a fazer: ainda não mede');
    SavingsActionService::setStatus($h, $id, 'doing', $today);
    $row = Database::selectOne('SELECT baseline_amount, started_at FROM savings_actions WHERE id = ?', [$id]);
    assert_same('300.00', $row['baseline_amount']);
    assert_same('2026-09-18', $row['started_at']);
    $ov = SavingsActionService::overview($h, $today);
    assert_same(180.0, $ov['items'][0]['measured'], '300 de base − 120 no mês');
    assert_same(180.0, $ov['measured']);
    assert_same(150.0, $ov['estimated']);
    SavingsActionService::setStatus($h, $id, 'done', $today);
    assert_same('180.00', Database::scalar('SELECT measured_saving_month FROM savings_actions WHERE id = ?', [$id]));
}
