<?php
// tests/SimulatorTest.php — simulador "e se" (cálculo puro sobre um ponto de partida montado à mão)
declare(strict_types=1);

use App\Services\SimulatorService;

function sim_baseline(): array
{
    return [
        'months_used' => 3, 'income' => 8000.0, 'expense' => 7500.0, 'essential' => 5000.0, 'surplus' => 500.0, 'reserve' => 10000.0,
        'categories' => [['id' => 2, 'name' => 'Alimentação', 'monthly' => 2400.0], ['id' => 9, 'name' => 'Lazer', 'monthly' => 600.0]],
        'subscriptions' => [['id' => 7, 'description' => 'Netflix', 'monthly' => 44.9], ['id' => 8, 'description' => 'Prime', 'monthly' => 19.9]],
        'goal' => ['id' => 1, 'name' => 'Reserva', 'target_amount' => '16000.00', 'saved_amount' => '10000.00', 'deadline' => null],
    ];
}

function test_simulator_cuts_and_cancellations(): void
{
    $r = SimulatorService::simulate(sim_baseline(), [2 => 400.0, 9 => 1000.0], [7]);
    assert_same(3, count($r['lines']));
    assert_true($r['lines'][1]['capped'], 'corte maior que a média é limitado');
    assert_same(600.0, $r['lines'][1]['monthly']);
    assert_same(1044.9, $r['monthly_saving']);
    assert_same(500.0, $r['surplus_now']);
    assert_same(1544.9, $r['surplus_new']);
    assert_same(2.0, $r['reserve_months']);
    assert_same(6269.4, $r['horizons'][6]['saved_extra']);
    assert_same(12538.8, $r['horizons'][12]['saved_extra']);
    assert_same(3.2, $r['horizons'][12]['reserve_now'], '(10000 + 500×12) / 5000');
    assert_same(5.7, $r['horizons'][12]['reserve_new'], '(10000 + 1544,9×12) / 5000');
    assert_same(12, $r['goal']['months_now'], '6000 faltantes / 500');
    assert_same(4, $r['goal']['months_new'], '6000 / 1544,9 → 4 meses');
}

function test_simulator_with_negative_surplus(): void
{
    $b = sim_baseline();
    $b['surplus'] = -300.0;
    $r = SimulatorService::simulate($b, [], [8]);
    assert_same(19.9, $r['monthly_saving']);
    assert_null($r['goal']['months_now'], 'sobra negativa: sem previsão');
    assert_null($r['goal']['months_new']);
    assert_same(2.0, $r['horizons'][6]['reserve_new'], 'sobra negativa não consome a reserva na projeção');
}
