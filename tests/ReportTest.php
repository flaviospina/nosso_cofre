<?php
// tests/ReportTest.php — relatórios mensal/anual/conta, CSV e PDF (integração; PDF também verificado estruturalmente)
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

use App\Core\Database;
use App\Core\Pdf;
use App\Services\ReportService;

function test_report_monthly_and_annual(): void
{
    if (!db_available('goal_contributions')) { return; }
    $f = privacy_fixture();
    $h = (int) $f['household']; $acc = (int) $f['account']; $by = (int) $f['owner'];
    $now = gmdate('Y-m-d H:i:s');
    $tx = static function (string $date, string $amount, string $type, ?int $cat) use ($h, $acc, $by, $now): void {
        Database::insert('transactions', ['household_id' => $h, 'account_id' => $acc, 'created_by' => $by, 'responsible_user_id' => $by, 'type' => $type, 'amount' => $amount, 'date' => $date, 'description' => 'T', 'status' => 'paid', 'category_id' => $cat, 'created_at' => $now]);
    };
    $tx('2026-09-05', '5000.00', 'income', 19);
    $tx('2026-09-06', '1000.00', 'expense', 100);   // Moradia › Aluguel
    $tx('2026-09-07', '200.00', 'expense', 104);    // Moradia › Energia
    $tx('2026-09-08', '150.00', 'expense', 111);    // Alimentação › Padaria
    $tx('2026-08-06', '1000.00', 'expense', 100);
    $tx('2026-08-08', '400.00', 'expense', 111);
    $tx('2025-09-06', '800.00', 'expense', 100);
    $r = ReportService::monthly($h, '2026-09-01', null);
    assert_same(1380.0, $r['totals']['expense'], '1200 + 150 + 30 da fixture');
    assert_same(1400.0, $r['totals']['previous_expense']);
    assert_same(800.0, $r['totals']['last_year_expense']);
    assert_same(5000.0, $r['totals']['income']);
    assert_same('Moradia', $r['expenses'][0]['name']);
    assert_same(1200.0, $r['expenses'][0]['amount']);
    assert_same(200.0, $r['expenses'][0]['delta']);
    assert_same(2, count($r['expenses'][0]['children']));
    $ali = array_values(array_filter($r['expenses'], static fn(array $e): bool => $e['name'] === 'Alimentação'))[0];
    assert_same(-250.0, $ali['delta'], 'padaria caiu de 400 para 150');
    assert_same('Moradia', $r['ranking'][0]['name'], 'ranking: onde mais cresceu');
    $a = ReportService::annual($h, 2026, null);
    assert_same(12, count($a['rows']));
    assert_same(5000.0, $a['totals']['income']);
    assert_same(2780.0, $a['totals']['expense']);
    assert_same('setembro', $a['best']['label']);
    assert_same('agosto', $a['worst']['label']);
    assert_same(44, $a['totals']['savings_rate']);
    $t = ReportService::toTable('annual', $a);
    assert_same(14, count($t['rows']));
    assert_contains('5.000,00', $t['rows'][8][1]);
    $csv = ReportService::csv($t);
    assert_true(str_starts_with($csv, "\xEF\xBB\xBF"), 'BOM para o Excel');
    assert_contains("Mês;Receitas;Despesas;Saldo;Poupança\r\n", $csv);
    assert_contains('setembro;5.000,00;1.380,00;3.620,00;72%', $csv);
}

function test_report_account_statement_and_card_invoice(): void
{
    if (!db_available('goal_contributions')) { return; }
    $f = privacy_fixture();
    $h = (int) $f['household']; $acc = (int) $f['account']; $joint = (int) $f['joint']; $by = (int) $f['owner'];
    $now = gmdate('Y-m-d H:i:s');
    Database::execute('UPDATE accounts SET initial_balance = 500 WHERE id = ?', [$acc]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => $acc, 'created_by' => $by, 'type' => 'income', 'amount' => '1000.00', 'date' => '2026-09-03', 'description' => 'Salário', 'status' => 'paid', 'created_at' => $now]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => $acc, 'transfer_account_id' => $joint, 'created_by' => $by, 'type' => 'transfer', 'amount' => '200.00', 'date' => '2026-09-04', 'description' => 'Guardar', 'status' => 'paid', 'created_at' => $now]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => $acc, 'created_by' => $by, 'type' => 'expense', 'amount' => '50.00', 'date' => '2026-08-20', 'description' => 'Antes', 'status' => 'paid', 'created_at' => $now]);
    $s = ReportService::account($h, $acc, '2026-09-01');
    assert_false($s['is_card']);
    assert_same(450.0, $s['opening'], '500 − 50 de agosto');
    assert_same(1240.0, $s['closing'], '450 + 1000 − 10 (fixture) − 200');
    assert_same(3, count($s['rows']));
    // Cartão: fechamento dia 10 → fatura de setembro cobre 11/08 a 10/09
    $card = Database::insert('accounts', ['household_id' => $h, 'name' => 'Cartão', 'type' => 'credit_card', 'closing_day' => 10, 'due_day' => 17, 'created_at' => $now]);
    foreach (['2026-08-09' => '100.00', '2026-08-12' => '80.00', '2026-09-10' => '20.00', '2026-09-11' => '999.00'] as $d => $amt) {
        Database::insert('transactions', ['household_id' => $h, 'account_id' => $card, 'created_by' => $by, 'type' => 'expense', 'amount' => $amt, 'date' => $d, 'description' => 'Compra', 'status' => 'pending', 'created_at' => $now]);
    }
    $inv = ReportService::account($h, $card, '2026-09-01');
    assert_true($inv['is_card']);
    assert_same('2026-08-11', $inv['from']);
    assert_same('2026-09-10', $inv['to']);
    assert_same('2026-09-17', $inv['due']);
    assert_same(100.0, $inv['charges'], '80 + 20; 09/08 e 11/09 ficam fora');
    $t = ReportService::toTable('account', $inv);
    assert_same('Total da fatura', $t['rows'][count($t['rows']) - 1][1]);
    assert_same('100,00', $t['rows'][count($t['rows']) - 1][4]);
}

function test_pdf_structure_and_text(): void
{
    $pdf = new Pdf('Relatório mensal', 'Família Teste · setembro de 2026');
    $pdf->heading('Despesas por categoria');
    $pdf->paragraph('Comparação com o mês anterior: ação, coração, çãõ.');
    $rows = [];
    for ($i = 1; $i <= 70; $i++) {
        $rows[] = ['Categoria ' . $i, money($i * 10, false), '10%'];
    }
    $pdf->table(['Categoria', 'Valor', '%'], $rows, [0.6, 0.25, 0.15], ['L', 'R', 'R'], [69]);
    $out = $pdf->output();
    assert_true(str_starts_with($out, '%PDF-1.4'));
    assert_contains('/Count 2', $out, 'setenta linhas quebram em duas páginas');
    assert_contains('(Relat' . "\xF3" . 'rio mensal)', $out, 'acentos em WinAnsi');
    assert_contains('(Categoria 70)', $out);
    assert_contains('/Helvetica-Bold', $out);
    preg_match('/startxref\n(\d+)/', $out, $m);
    assert_same('xref', substr($out, (int) $m[1], 4), 'startxref aponta para a tabela xref');
    preg_match_all('/^(\d+) 0 obj/m', $out, $objs, PREG_OFFSET_CAPTURE);
    $lines = explode("\n", substr($out, (int) $m[1]));
    foreach ($objs[1] as [$id, $offset]) {
        assert_same(sprintf('%010d', $objs[0][(int) $id - 1][1]), substr($lines[2 + (int) $id], 0, 10), "offset do objeto {$id}");
    }
}
