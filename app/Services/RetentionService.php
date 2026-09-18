<?php
// app/Services/RetentionService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Retenção (LGPD, §3.3.6): purga periódica conforme os prazos da tabela settings.
 */
final class RetentionService
{
    /** @return array<string,int> contagem por item */
    public static function run(): array
    {
        $months = static fn(string $key, int $default): int => max(1, (int) (Database::scalar("SELECT `value` FROM settings WHERE `key` = ?", [$key]) ?? $default));
        $now = time();
        $result = [];

        $result['tentativas_de_login'] = Database::execute('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', strtotime('-' . $months('retention.login_attempts_months', 12) . ' months', $now))]);
        $result['auditoria'] = Database::execute('DELETE FROM audit_logs WHERE created_at < ?', [gmdate('Y-m-d H:i:s', strtotime('-' . $months('retention.audit_logs_months', 24) . ' months', $now))]);
        $result['lixeira'] = self::purgeTrash($months('retention.trash_days', 30));
        $result['exportacoes'] = ExportService::purgeExpired();
        $result['sessoes'] = Database::execute('DELETE FROM sessions WHERE last_activity < ?', [gmdate('Y-m-d H:i:s', $now - $months('retention.sessions_days', 14) * 86400)]);
        $result['alertas'] = Database::execute('DELETE FROM alerts WHERE created_at < ? AND (read_at IS NOT NULL OR sent_at IS NOT NULL)', [gmdate('Y-m-d H:i:s', $now - $months('retention.alerts_days', 90) * 86400)]);
        $result['tokens'] = Database::execute('DELETE FROM remember_tokens WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', $now)])
            + Database::execute('DELETE FROM password_resets WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', $now - 86400)])
            + Database::execute('DELETE FROM email_verifications WHERE expires_at < ? AND verified_at IS NULL', [gmdate('Y-m-d H:i:s', $now - 86400)])
            + Database::execute('UPDATE invitations SET revoked_at = ? WHERE expires_at < ? AND accepted_at IS NULL AND revoked_at IS NULL', [gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', $now)]);
        $result['outbox'] = Database::execute("DELETE FROM email_outbox WHERE status = 'sent' AND created_at < ?", [gmdate('Y-m-d H:i:s', $now - 30 * 86400)]);
        $result['exclusoes_executadas'] = PrivacyService::executeDueDeletions();
        $result['backups'] = self::purgeBackups((int) Config::get('backup.retention_days', 30));
        return $result;
    }

    /** Lixeira: lançamentos com deleted_at há mais de N dias somem de vez (com anexo). */
    public static function purgeTrash(int $days): int
    {
        $limit = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $rows = Database::select('SELECT id, attachment_path FROM transactions WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$limit]);
        foreach ($rows as $row) {
            if ($row['attachment_path'] !== null) {
                $file = (string) Config::get('paths.uploads') . '/' . $row['attachment_path'];
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        return Database::execute('DELETE FROM transactions WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$limit]);
    }

    private static function purgeBackups(int $days): int
    {
        $dir = (string) Config::get('paths.backups');
        $count = 0;
        foreach (glob($dir . '/*.enc') ?: [] as $file) {
            if (filemtime($file) < time() - $days * 86400) {
                @unlink($file);
                $count++;
            }
        }
        return $count;
    }
}
