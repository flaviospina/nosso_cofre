<?php
// tests/RecurrenceTest.php — cálculo de ocorrências (puro) e geração idempotente de agendados (integração)
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

use App\Core\Database;
use App\Services\RecurrenceService;
use App\Services\SubscriptionService;

function rec_rule(array $over = []): array
{
    return $over + ['frequency' => 'monthly', 'interval_count' => 1, 'day_of_month' => 10, 'day_of_week' => null, 'month_of_year' => null, 'start_date' => '2026-01-01', 'end_date' => null];
}

function test_recurrence_monthly_clamps_and_advances(): void
{
    $d = static fn(string $s): DateTimeImmutable => new DateTimeImmutable($s);
    assert_same('2026-09-10', RecurrenceService::nextOccurrence(rec_rule(), $d('2026-09-10'))->format('Y-m-d'), 'mesmo dia conta');
    assert_same('2026-10-10', RecurrenceService::nextOccurrence(rec_rule(), $d('2026-09-11'))->format('Y-m-d'));
    assert_same('2026-02-28', RecurrenceService::nextOccurrence(rec_rule(['day_of_month' => 31]), $d('2026-02-01'))->format('Y-m-d'), '31 vira último dia');
    assert_same('2026-01-10', RecurrenceService::nextOccurrence(rec_rule(), $d('2025-06-01'))->format('Y-m-d'), 'antes do início cai na primeira');
    assert_null(RecurrenceService::nextOccurrence(rec_rule(['end_date' => '2026-09-30']), $d('2026-10-01')), 'depois do fim não há ocorrência');
    assert_same('2026-03-01', RecurrenceService::nextOccurrence(rec_rule(['interval_count' => 2, 'day_of_month' => 1]), $d('2026-01-02'))->format('Y-m-d'), 'a cada 2 meses');
}

function test_recurrence_weekly_yearly_custom(): void
{
    $d = static fn(string $s): DateTimeImmutable => new DateTimeImmutable($s);
    assert_same('2026-09-21', RecurrenceService::nextOccurrence(rec_rule(['frequency' => 'weekly', 'day_of_week' => 1]), $d('2026-09-18'))->format('Y-m-d'), 'próxima segunda');
    assert_same('2026-09-18', RecurrenceService::nextOccurrence(rec_rule(['frequency' => 'weekly', 'day_of_week' => 5]), $d('2026-09-18'))->format('Y-m-d'), 'hoje é sexta');
    assert_same('2027-03-20', RecurrenceService::nextOccurrence(rec_rule(['frequency' => 'yearly', 'month_of_year' => 3, 'day_of_month' => 20]), $d('2026-09-18'))->format('Y-m-d'), 'PLR do ano que vem');
    assert_same('2026-03-20', RecurrenceService::nextOccurrence(rec_rule(['frequency' => 'yearly', 'month_of_year' => 3, 'day_of_month' => 20]), $d('2026-02-01'))->format('Y-m-d'));
    assert_same('2026-01-31', RecurrenceService::nextOccurrence(rec_rule(['frequency' => 'custom', 'interval_count' => 15]), $d('2026-01-20'))->format('Y-m-d'), 'a cada 15 dias desde 01/01');
    assert_same(round(52 / 12, 4), round(RecurrenceService::monthlyFactor(['frequency' => 'weekly', 'interval_count' => 1]), 4));
    assert_same(1.0, RecurrenceService::monthlyFactor(['frequency' => 'monthly', 'interval_count' => 1]));
}

function test_recurrence_generate_is_idempotent_and_respects_horizon(): void
{
    if (!db_available('recurring_rules')) { return; }
    $f = privacy_fixture();
    $h = (int) $f['household'];
    $now = gmdate('Y-m-d H:i:s');
    $today = new DateTimeImmutable('2026-09-18');
    $rule = Database::insert('recurring_rules', ['household_id' => $h, 'description' => 'Aluguel', 'kind' => 'expense', 'account_id' => (int) $f['account'], 'responsible_user_id' => (int) $f['owner'], 'expected_amount' => '1000.00', 'frequency' => 'monthly', 'day_of_month' => 5, 'start_date' => '2026-09-01', 'next_run_date' => '2026-09-05', 'generate_days_ahead' => 45, 'is_active' => 1, 'created_at' => $now]);
    $n = RecurrenceService::generate($h, $today);
    assert_same(2, $n, 'gera 05/09 (atrasada) e 05/10 (dentro de 45 dias), não 05/11');
    $rows = Database::select("SELECT date, status, amount, recurring_id FROM transactions WHERE household_id = ? AND recurring_id = ? ORDER BY date", [$h, $rule]);
    assert_same(['2026-09-05', '2026-10-05'], array_column($rows, 'date'));
    assert_same('scheduled', $rows[0]['status']);
    assert_same(0, RecurrenceService::generate($h, $today), 'segunda chamada não duplica');
    assert_same('2026-11-05', Database::scalar('SELECT next_run_date FROM recurring_rules WHERE id = ?', [$rule]));
    // Média das últimas pagas
    Database::execute("UPDATE transactions SET status = 'paid', amount = 900 WHERE recurring_id = ? AND date = '2026-09-05'", [$rule]);
    Database::execute("UPDATE recurring_rules SET expected_amount_source = 'average' WHERE id = ?", [$rule]);
    $r = Database::selectOne('SELECT * FROM recurring_rules WHERE id = ?', [$rule]);
    assert_same(900.0, RecurrenceService::expectedAmount($h, $r));
    // Regra encerrada some do cursor
    Database::execute("UPDATE recurring_rules SET end_date = '2026-10-31' WHERE id = ?", [$rule]);
    RecurrenceService::generate($h, $today);
    assert_same(0, (int) Database::scalar('SELECT is_active FROM recurring_rules WHERE id = ?', [$rule]), 'sem próxima ocorrência → inativa');
}

function test_subscription_radar_flags_duplicates_and_detected(): void
{
    if (!db_available('recurring_rules')) { return; }
    $f = privacy_fixture();
    $h = (int) $f['household'];
    $now = gmdate('Y-m-d H:i:s');
    $today = new DateTimeImmutable('2026-09-18');
    foreach ([1, 2] as $i) {
        Database::insert('recurring_rules', ['household_id' => $h, 'description' => 'Amazon Prime', 'kind' => 'expense', 'account_id' => (int) $f['account'], 'expected_amount' => '19.90', 'frequency' => 'monthly', 'day_of_month' => $i, 'start_date' => '2026-01-01', 'is_subscription' => 1, 'is_active' => 1, 'last_usage_confirmed_at' => '2026-09-01', 'created_at' => $now]);
    }
    $spotify = Database::insert('recurring_rules', ['household_id' => $h, 'description' => 'Spotify', 'kind' => 'expense', 'account_id' => (int) $f['account'], 'expected_amount' => '21.90', 'frequency' => 'monthly', 'day_of_month' => 3, 'start_date' => '2026-01-01', 'is_subscription' => 1, 'is_active' => 1, 'created_at' => $now]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => (int) $f['account'], 'created_by' => (int) $f['owner'], 'type' => 'expense', 'amount' => '21.90', 'date' => '2026-07-03', 'description' => 'Spotify', 'status' => 'paid', 'recurring_id' => $spotify, 'created_at' => $now]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => (int) $f['account'], 'created_by' => (int) $f['owner'], 'type' => 'expense', 'amount' => '27.90', 'date' => '2026-08-03', 'description' => 'Spotify', 'status' => 'paid', 'recurring_id' => $spotify, 'created_at' => $now]);
    foreach (['2026-06-12', '2026-07-12', '2026-08-12'] as $d) {
        Database::insert('transactions', ['household_id' => $h, 'account_id' => (int) $f['account'], 'created_by' => (int) $f['owner'], 'type' => 'expense', 'amount' => '39.90', 'date' => $d, 'description' => 'DISNEY PLUS', 'status' => 'paid', 'created_at' => $now]);
    }
    $radar = SubscriptionService::radar($h, $today);
    $byName = [];
    foreach ($radar['items'] as $it) { $byName[$it['description']][] = $it; }
    assert_same(2, count($byName['Amazon Prime']));
    assert_true(in_array('duplicate', array_column($byName['Amazon Prime'][0]['flags'], 'kind'), true), 'Amazon Prime duplicada');
    $spotifyFlags = array_column($byName['Spotify'][0]['flags'], 'kind');
    assert_true(in_array('increase', $spotifyFlags, true), 'aumento de 21,90 → 27,90');
    assert_true(in_array('unused', $spotifyFlags, true), 'sem uso confirmado');
    assert_same(1, count($radar['detected']));
    assert_same('DISNEY PLUS', $radar['detected'][0]['description']);
    assert_same(39.9, $radar['detected'][0]['monthly_amount']);
    assert_same(round(19.9 * 2 + 21.9 + 39.9, 2), $radar['monthly_total']);
    assert_true($radar['alerts'] >= 2);
}
