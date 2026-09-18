<?php
// app/Services/AlertService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Central de avisos: cria (com deduplicação), lista, marca como lido. A entrega (push/e-mail) é do NotificationScheduler. */
final class AlertService
{
    /**
     * Cria um aviso para o usuário. Devolve o id ou null quando o dedupe_key já existe.
     * @param array<string,mixed> $opts severity, color, sound, url, payload, dedupe_key, channel ('push'|'email'|'both'|'none'), household_id, scheduled_for
     */
    public static function create(int $userId, string $type, string $title, ?string $body = null, array $opts = []): ?int
    {
        $meta = NotificationSettingsService::TYPES[$type] ?? ['color' => '#0f766e', 'sound' => 'none', 'severity' => 'info'];
        if (!empty($opts['dedupe_key'])) {
            $exists = Database::scalar('SELECT id FROM alerts WHERE user_id = ? AND dedupe_key = ?', [$userId, (string) $opts['dedupe_key']]);
            if ($exists !== null) {
                return null;
            }
        }
        return Database::insert('alerts', [
            'household_id'  => $opts['household_id'] ?? null,
            'user_id'       => $userId,
            'type'          => $type,
            'severity'      => $opts['severity'] ?? $meta['severity'],
            'color'         => $opts['color'] ?? $meta['color'],
            'sound'         => ($opts['sound'] ?? $meta['sound']) === 'none' ? null : ($opts['sound'] ?? $meta['sound']),
            'title'         => mb_substr($title, 0, 150),
            'body'          => $body,
            'payload'       => isset($opts['payload']) ? json_encode($opts['payload'], JSON_UNESCAPED_UNICODE) : null,
            'url'           => isset($opts['url']) ? mb_substr((string) $opts['url'], 0, 255) : null,
            'dedupe_key'    => isset($opts['dedupe_key']) ? mb_substr((string) $opts['dedupe_key'], 0, 120) : null,
            'channel'       => $opts['channel'] ?? 'none',
            'scheduled_for' => $opts['scheduled_for'] ?? null,
            'sent_at'       => ($opts['channel'] ?? 'none') === 'none' ? gmdate('Y-m-d H:i:s') : null,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function list(int $userId, ?string $type = null, int $limit = 100): array
    {
        $sql = 'SELECT * FROM alerts WHERE user_id = ?' . ($type !== null ? ' AND type = ?' : '') . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit;
        $rows = Database::select($sql, $type !== null ? [$userId, $type] : [$userId]);
        foreach ($rows as &$r) {
            $r['payload'] = $r['payload'] !== null ? json_decode((string) $r['payload'], true) : null;
        }
        unset($r);
        return $rows;
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM alerts WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    /** Avisos novos desde um id (para o toast em tempo real). @return list<array<string,mixed>> */
    public static function since(int $userId, int $afterId): array
    {
        return Database::select('SELECT id, type, severity, color, sound, title, body, url, created_at FROM alerts WHERE user_id = ? AND id > ? AND read_at IS NULL ORDER BY id LIMIT 10', [$userId, $afterId]);
    }

    public static function markRead(int $userId, int $id): void
    {
        Database::execute('UPDATE alerts SET read_at = ? WHERE user_id = ? AND id = ? AND read_at IS NULL', [gmdate('Y-m-d H:i:s'), $userId, $id]);
    }

    public static function markAllRead(int $userId): int
    {
        return Database::execute('UPDATE alerts SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [gmdate('Y-m-d H:i:s'), $userId]);
    }

    /** Link assinado para ações a partir da notificação (ex.: "marcar como pago") sem depender de sessão + CSRF. */
    public static function actionToken(int $userId, int $transactionId, string $action = 'pay'): string
    {
        $expires = time() + 48 * 3600; // curto: o link executa uma ação (idempotente) com a sessão do próprio usuário
        $data = "{$action}|{$userId}|{$transactionId}|{$expires}";
        return \App\Core\WebPush::b64url($data . '|' . \App\Core\Crypto::sign($data));
    }

    /** @return array{action:string,user_id:int,transaction_id:int}|null */
    public static function parseActionToken(string $token): ?array
    {
        $parts = explode('|', \App\Core\WebPush::b64decode($token));
        if (count($parts) !== 5) {
            return null;
        }
        [$action, $userId, $txId, $expires, $sig] = $parts;
        if ((int) $expires < time() || !\App\Core\Crypto::verifySignature("{$action}|{$userId}|{$txId}|{$expires}", $sig)) {
            return null;
        }
        return ['action' => $action, 'user_id' => (int) $userId, 'transaction_id' => (int) $txId];
    }
}
