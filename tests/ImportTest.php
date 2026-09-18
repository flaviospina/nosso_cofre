<?php
// tests/ImportTest.php — importação CSV/OFX: valores, datas, detecção de colunas, parsing e duplicados (parte com banco, pulada se indisponível)
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

use App\Core\Database;
use App\Models\Transaction;
use App\Services\ImportService;

function import_tmp(string $content, string $ext): string
{
    $path = sys_get_temp_dir() . '/nc-import-' . bin2hex(random_bytes(4)) . '.' . $ext;
    file_put_contents($path, $content);
    return $path;
}

function test_import_parse_amount_formats(): void
{
    assert_same(1234.56, ImportService::parseAmount('1.234,56'));
    assert_same(-1234.56, ImportService::parseAmount('-1234.56'));
    assert_same(1234.56, ImportService::parseAmount('R$ 1.234,56'));
    assert_same(-12.0, ImportService::parseAmount('(12,00)'));
    assert_same(-50.0, ImportService::parseAmount('50,00 D'));
    assert_same(1234.5, ImportService::parseAmount('1,234.50'));
    assert_null(ImportService::parseAmount('abc'));
    assert_null(ImportService::parseAmount(''));
}

function test_import_parse_date_formats(): void
{
    assert_same('2026-09-05', ImportService::parseDate('05/09/2026', 'd/m/Y'));
    assert_same('2026-09-05', ImportService::parseDate('2026-09-05', 'Y-m-d'));
    assert_same('2026-09-05', ImportService::parseDate('09/05/2026', 'm/d/Y'));
    assert_same('2026-09-05', ImportService::parseDate('20260905', 'Ymd'));
    assert_same('2026-09-05', ImportService::parseDate('05/09/2026 10:30', 'd/m/Y'), 'tolera hora no fim');
    assert_null(ImportService::parseDate('31/02/2026x', 'd/m/Y'));
    assert_null(ImportService::parseDate('', 'd/m/Y'));
}

function test_import_inspect_csv_guesses_columns(): void
{
    $path = import_tmp("Data;Descrição;Valor\n01/09/2026;MERCADO BOM PRECO;-120,50\n02/09/2026;PIX RECEBIDO JOAO;300,00\n", 'csv');
    $i = ImportService::inspectCsv($path);
    unlink($path);
    assert_same(';', $i['delimiter']);
    assert_true($i['has_header']);
    assert_same(['Data', 'Descrição', 'Valor'], $i['columns']);
    assert_same(2, $i['total']);
    assert_same(0, $i['mapping']['date']);
    assert_same(1, $i['mapping']['description']);
    assert_same(2, $i['mapping']['amount']);
}

function test_import_parse_csv_with_amount_column(): void
{
    $path = import_tmp("Data;Descrição;Valor\n01/09/2026;MERCADO BOM PRECO;-120,50\n02/09/2026;PIX RECEBIDO JOAO;300,00\nxx/09/2026;LINHA RUIM;1,00\n", 'csv');
    $p = ImportService::parseCsv($path, ['delimiter' => ';', 'has_header' => true, 'date' => 0, 'description' => 1, 'amount' => 2, 'debit' => null, 'credit' => null, 'type' => null, 'date_format' => 'd/m/Y', 'invert' => false]);
    unlink($path);
    assert_same(2, count($p['rows']));
    assert_same(1, count($p['errors']), 'linha com data inválida vira erro');
    assert_same('2026-09-01', $p['rows'][0]['date']);
    assert_same('expense', $p['rows'][0]['type']);
    assert_same('120.50', $p['rows'][0]['amount']);
    assert_same('income', $p['rows'][1]['type']);
    assert_same('300.00', $p['rows'][1]['amount']);
}

function test_import_parse_csv_invert_and_debit_credit(): void
{
    $path = import_tmp("2026-09-01,Compra,45.90,\n2026-09-02,Estorno,,45.90\n", 'csv');
    $p = ImportService::parseCsv($path, ['delimiter' => ',', 'has_header' => false, 'date' => 0, 'description' => 1, 'amount' => null, 'debit' => 2, 'credit' => 3, 'type' => null, 'date_format' => 'Y-m-d', 'invert' => false]);
    assert_same('expense', $p['rows'][0]['type']);
    assert_same('income', $p['rows'][1]['type']);
    // Fatura de cartão: valor positivo é gasto → invert
    $p2 = ImportService::parseCsv($path, ['delimiter' => ',', 'has_header' => false, 'date' => 0, 'description' => 1, 'amount' => 2, 'debit' => null, 'credit' => null, 'type' => null, 'date_format' => 'Y-m-d', 'invert' => true]);
    unlink($path);
    assert_same('expense', $p2['rows'][0]['type'], 'com invert, positivo vira despesa');
}

function test_import_parse_ofx(): void
{
    $ofx = "OFXHEADER:100\nDATA:OFXSGML\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKACCTFROM><ACCTID>12345-6</ACCTID></BANKACCTFROM><BANKTRANLIST>\n"
        . "<STMTTRN><TRNTYPE>DEBIT</TRNTYPE><DTPOSTED>20260903120000[-3:BRT]</DTPOSTED><TRNAMT>-89.90</TRNAMT><FITID>2026090301</FITID><MEMO>NETFLIX.COM</MEMO></STMTTRN>\n"
        . "<STMTTRN><TRNTYPE>CREDIT</TRNTYPE><DTPOSTED>20260905</DTPOSTED><TRNAMT>1500.00</TRNAMT><FITID>2026090502</FITID><NAME>SALARIO</NAME></STMTTRN>\n"
        . "</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>";
    $path = import_tmp($ofx, 'ofx');
    $p = ImportService::parseOfx($path);
    unlink($path);
    assert_same(2, count($p['rows']));
    assert_same('2026-09-03', $p['rows'][0]['date']);
    assert_same('expense', $p['rows'][0]['type']);
    assert_same('89.90', $p['rows'][0]['amount']);
    assert_same('2026090301', $p['rows'][0]['fitid']);
    assert_same('NETFLIX.COM', $p['rows'][0]['description']);
    assert_same('income', $p['rows'][1]['type']);
    assert_same('SALARIO', $p['rows'][1]['description']);
    assert_contains('12345-6', (string) $p['account']);
}

function test_import_hash_is_stable_and_case_insensitive(): void
{
    $a = Transaction::importHash('2026-09-01', '120.5', 'Mercado  Bom Preco', 'expense');
    $b = Transaction::importHash('2026-09-01', '120.50', 'mercado bom preco', 'expense');
    assert_same($a, $b);
    assert_true($a !== Transaction::importHash('2026-09-01', '120.50', 'mercado bom preco', 'income'));
}

function test_import_enrich_marks_duplicates_in_db_and_in_file(): void
{
    if (!db_available('category_rules')) { return; }
    $f = privacy_fixture();
    $h = (int) $f['household'];
    $rows = [
        ['date' => '2026-09-01', 'description' => 'Padaria', 'amount' => '10.00', 'type' => 'expense', 'fitid' => null],   // já existe no lar (fixture)
        ['date' => '2026-09-10', 'description' => 'Nova compra', 'amount' => '33.00', 'type' => 'expense', 'fitid' => null],
        ['date' => '2026-09-10', 'description' => 'Nova compra', 'amount' => '33.00', 'type' => 'expense', 'fitid' => null], // repetida no arquivo
    ];
    // a fixture não grava import_hash; simula um lançamento importado antes
    Database::execute('UPDATE transactions SET import_hash = ? WHERE household_id = ? AND description = ?', [Transaction::importHash('2026-09-01', '10.00', 'Padaria', 'expense'), $h, 'Padaria']);
    $out = ImportService::enrich($rows, $h);
    assert_true($out[0]['duplicate'], 'já existente no banco');
    assert_false($out[1]['duplicate']);
    assert_true($out[2]['duplicate'], 'repetida dentro do arquivo');
}
