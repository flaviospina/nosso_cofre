<?php
// tests/InsightsTest.php — score (puro) e indicadores do painel (integração)
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

use App\Core\Database;
use App\Services\InsightsService;

function test_insights_score_components(): void
{
    $s = InsightsService::score(['savings_rate' => 25, 'budgets_total' => 4, 'budgets_over' => 0, 'overdue_count' => 0, 'reserve_months' => 6.5, 'superfluous_share' => 20, 'subscription_alerts' => 0, 'has_expenses' => true]);
    assert_same(100, $s['total']);
    assert_same('Ótima', $s['level']);
    $s = InsightsService::score(['savings_rate' => 10, 'budgets_total' => 4, 'budgets_over' => 1, 'overdue_count' => 2, 'reserve_months' => 1.5, 'superfluous_share' => 45, 'subscription_alerts' => 1, 'has_expenses' => true]);
    $by = array_column($s['items'], 'points', 'label');
    assert_same(13, $by['Taxa de poupança'], '10% de 20% → 12,5 ≈ 13');
    assert_same(15, $by['Orçamentos'], '1 de 4 estourado → 15');
    assert_same(5, $by['Contas em dia']);
    assert_same(5, $by['Reserva de emergência'], '1,5 de 6 meses → 5');
    assert_same(5, $by['Gasto supérfluo'], '45% → metade dos 10');
    assert_same(5, $by['Assinaturas']);
    assert_same(48, $s['total']);
    assert_same('Atenção', $s['level']);
    $s = InsightsService::score(['savings_rate' => null, 'budgets_total' => 0, 'budgets_over' => 0, 'overdue_count' => 5, 'reserve_months' => 0, 'superfluous_share' => 0, 'subscription_alerts' => 3, 'has_expenses' => false]);
    assert_same(15, $s['total'], 'sem receita, sem orçamento (10 neutros), sem despesa (5 neutros), tudo atrasado');
    assert_same('Crítica', $s['level']);
}

function test_insights_dashboard_numbers(): void
{
    if (!db_available('goal_contributions')) { return; }
    $f = privacy_fixture(true);
    $h = (int) $f['household']; $acc = (int) $f['account']; $owner = (int) $f['owner']; $member = (int) $f['member'];
    $today = new DateTimeImmutable('2026-09-18');
    $now = gmdate('Y-m-d H:i:s');
    $tx = static function (string $date, string $amount, string $type, ?int $cat, ?int $resp, string $status = 'paid') use ($h, $acc, $owner, $now): void {
        Database::insert('transactions', ['household_id' => $h, 'account_id' => $acc, 'created_by' => $owner, 'responsible_user_id' => $resp, 'type' => $type, 'amount' => $amount, 'date' => $date, 'description' => 'T', 'status' => $status, 'category_id' => $cat, 'created_at' => $now]);
    };
    // fixture: 10,00 (01/09) e 20,00 (02/09) pagas pelo membro, sem categoria (supérfluo)
    $tx('2026-09-05', '4000.00', 'income', 19, $owner);
    $tx('2026-09-06', '1000.00', 'expense', 100, $owner);            // Aluguel (essencial, Moradia)
    $tx('2026-09-07', '300.00', 'expense', 181, $member, 'pending');  // Cinema (supérfluo, Lazer), pendente
    $tx('2026-09-25', '500.00', 'expense', 104, $owner, 'scheduled'); // agendada: só na projeção
    $tx('2026-09-10', '80.00', 'expense', 111, $owner, 'pending');    // vencida (atrasada em 18/09)
    $tx('2026-08-10', '900.00', 'expense', 100, $owner);
    $d = InsightsService::dashboard($h, '2026-09-01', null, $today);
    assert_same(4000.0, $d['month']['income']);
    assert_same(1410.0, $d['month']['expense'], '1000 + 300 + 80 + 30 da fixture (agendada não conta)');
    assert_same(2590.0, $d['month']['balance']);
    assert_same(2090.0, $d['month']['projected'], 'projetado desconta a agendada de 500');
    assert_same(12, count($d['series']));
    assert_same('2026-09', $d['series'][11]['month']);
    assert_same(900.0, $d['series'][10]['expense']);
    assert_same(65, $d['savings_rate']);
    assert_same('Moradia', $d['by_category'][0]['name']);
    assert_same(1000.0, $d['by_category'][0]['amount']);
    assert_same(71, $d['by_category'][0]['pct']);
    $members = array_column($d['by_member'], 'amount', 'name');
    assert_same(1080.0, $members['Tit ' . substr($f['email'], 4, 8)] ?? $members[array_key_first($members)]);
    assert_same(2, $d['overdue'], 'a de 10/09 e a de 07/09 (pendente) estão vencidas');
    assert_true(count($d['upcoming']) >= 1);
    assert_same('late', $d['upcoming'][0]['urgency']);
    assert_same(410.0, $d['superfluous']['amount'], '300 cinema + 80 padaria + 30 sem categoria');
    assert_true($d['score']['total'] > 0 && $d['score']['total'] <= 100);
    // Filtro por membro
    $dm = InsightsService::dashboard($h, '2026-09-01', $member, $today);
    assert_same(330.0, $dm['month']['expense']);
    assert_same(0.0, $dm['month']['income']);
    assert_null($dm['savings_rate']);
}
