<?php
// tests/BackupTest.php — backup criptografado: dump, cifra/decifra, divisão de comandos e restauração (integração)
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\BackupService;

function test_backup_split_statements(): void
{
    $sql = "-- comentário\nCREATE TABLE t (a INT);\nINSERT INTO t VALUES ('a;b', 'c\\'d');\n\nINSERT INTO t VALUES (2)";
    $st = BackupService::splitStatements($sql);
    assert_same(3, count($st));
    assert_same("INSERT INTO t VALUES ('a;b', 'c\\'d')", $st[1], 'ponto e vírgula e aspas dentro de string');
}

function test_backup_create_open_and_restore(): void
{
    if (!db_available('cron_runs')) { return; }
    $key = (string) Config::get('backup.key', '');
    if (strlen($key) < 32) { return; }   // sem BACKUP_KEY no .env de teste
    $path = BackupService::create();
    assert_true(is_file($path));
    assert_true(str_ends_with($path, '.sql.gz.enc'));
    $sql = BackupService::open($path);
    assert_contains('CREATE TABLE `users`', $sql);
    assert_contains('INSERT INTO `categories`', $sql);
    assert_contains('SET FOREIGN_KEY_CHECKS = 1', $sql);
    assert_true(in_array(basename($path), array_column(BackupService::list(), 'name'), true));
    assert_true(BackupService::madeToday());
    // Restauração de um trecho numa tabela temporária (não mexe nas tabelas reais)
    $n = BackupService::restore("DROP TABLE IF EXISTS `zz_restore_test`;\nCREATE TABLE `zz_restore_test` (id INT, name VARCHAR(20));\nINSERT INTO `zz_restore_test` (`id`, `name`) VALUES\n(1, 'a;b'),\n(2, 'ação');");
    assert_same(3, $n);
    assert_same('ação', Database::scalar('SELECT name FROM zz_restore_test WHERE id = 2'));
    Database::execute('DROP TABLE zz_restore_test');
    assert_same(0, BackupService::purge(365));
    unlink($path);
}
