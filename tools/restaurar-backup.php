<?php
// tools/restaurar-backup.php — restaura um backup criptografado no banco do .env (linha de comando).
// Uso: php tools/restaurar-backup.php storage/backups/nosso-cofre-AAAA-MM-DD-HHMM.sql.gz.enc [--somente-sql > arquivo.sql]
// Sem terminal no servidor? Use --somente-sql no seu computador para obter o .sql e importe pelo phpMyAdmin (ver LEIA-ME §3.16).
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Informe o caminho do arquivo .sql.gz.enc\n");
    exit(1);
}
$sql = \App\Services\BackupService::open($file, getenv('BACKUP_KEY') ?: null);
if (in_array('--somente-sql', $argv, true)) {
    echo $sql;
    exit(0);
}
fwrite(STDERR, "ATENÇÃO: isto substitui TODAS as tabelas do banco " . \App\Core\Config::get('db.name') . ". Digite SIM para continuar: ");
$confirm = trim((string) fgets(STDIN));
if ($confirm !== 'SIM') {
    fwrite(STDERR, "Cancelado.\n");
    exit(1);
}
$n = \App\Services\BackupService::restore($sql);
echo "Restaurado: {$n} comandos executados.\n";
