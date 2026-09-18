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

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Mailer;

$startedAt = microtime(true);
$lines = [];

try {
    if (Database::isConfigured() && Database::ping()) {
        Database::execute(
            'INSERT INTO cron_runs (task, started_at, finished_at, status, message) VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)',
            ['heartbeat', 'ok', 'Runner ativo: outbox de e-mail e limpeza de sessões/tokens']
        );
        $lines[] = 'heartbeat: ok';

        // Fase 2: e-mails pendentes (falha de SMTP) e limpeza de sessões/tokens expirados
        $sent = Mailer::flushPending(20);
        $lines[] = "e-mails reenviados: {$sent}";
        $absoluteHours = max(1, (int) Config::get('security.session_absolute_hours', 12));
        $sessions = Database::execute('DELETE FROM sessions WHERE last_activity < ?', [gmdate('Y-m-d H:i:s', time() - $absoluteHours * 3600)]);
        $tokens = Database::execute('DELETE FROM remember_tokens WHERE expires_at < ?', [gmdate('Y-m-d H:i:s')]);
        $tokens += Database::execute('DELETE FROM password_resets WHERE expires_at < ? OR used_at IS NOT NULL', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        $tokens += Database::execute('DELETE FROM email_verifications WHERE expires_at < ? AND verified_at IS NULL', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        $lines[] = "sessões expiradas removidas: {$sessions}; tokens expirados removidos: {$tokens}";

        // Fase 3: retenção LGPD (uma vez por hora basta) e exclusões agendadas
        $lastRetention = Database::scalar("SELECT MAX(started_at) FROM cron_runs WHERE task = 'retention'");
        if ($lastRetention === null || strtotime((string) $lastRetention . ' UTC') < time() - 3600) {
            $summary = \App\Services\RetentionService::run();
            $summary['importacoes_temporarias'] = \App\Services\ImportService::purgeStale();
            Database::execute('INSERT INTO cron_runs (task, started_at, finished_at, status, message) VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)', ['retention', 'ok', json_encode($summary)]);
            $lines[] = 'retenção: ' . json_encode($summary, JSON_UNESCAPED_UNICODE);
        }
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
