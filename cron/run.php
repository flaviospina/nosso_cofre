<?php
// cron/run.php — ponto de entrada do cron (cPanel a cada 5 minutos).
// Pode ser executado por linha de comando ("php -q /home/usuario/public_html/cofre/cron/run.php")
// ou por URL protegida por token (https://itthrive.com.br/cofre/cron/run?token=...), que inclui este arquivo.
// As tarefas (recorrências, alertas, resumos, pushes, retenção, backup) entram nas fases 5 a 7;
// nesta fase o runner só registra a execução, para validar o agendamento no cPanel.
declare(strict_types=1);

$calledFromWeb = defined('APP_ROOT');
if (!$calledFromWeb) {
    if (PHP_SAPI !== 'cli') {
        // Acesso HTTP direto a esta pasta é bloqueado pelo .htaccess; este é só um segundo cinto.
        http_response_code(403);
        echo 'Acesso negado.';
        exit;
    }
    require dirname(__DIR__) . '/app/bootstrap.php';
}

use App\Core\Database;
use App\Core\Logger;

$startedAt = microtime(true);
$lines = [];

try {
    if (Database::isConfigured() && Database::ping()) {
        Database::execute(
            'INSERT INTO cron_runs (task, started_at, finished_at, status, message) VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)',
            ['heartbeat', 'ok', 'Fase 1: runner ativo, nenhuma tarefa agendada ainda']
        );
        $lines[] = 'heartbeat: ok';
    } else {
        $lines[] = 'heartbeat: banco indisponível';
    }
} catch (Throwable $e) {
    Logger::error('Falha no cron', ['exception' => $e]);
    $lines[] = 'erro: ' . $e->getMessage();
}

$lines[] = sprintf('tempo: %.3fs', microtime(true) - $startedAt);
$output = implode("\n", $lines) . "\n";

if ($calledFromWeb) {
    return $output;
}
echo $output;
