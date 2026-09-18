<?php
// app/Services/PushService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\WebPush;

/** Assinaturas push por aparelho e entrega com fallback (3 falhas seguidas → assinatura desativada e usuário avisado por e-mail). */
final class PushService
{
    public static function configured(): bool
    {
        return (string) Config::get('vapid.public', '') !== '' && (string) Config::get('vapid.private', '') !== '';
    }

    /** Registra (ou reativa) a assinatura de um aparelho. @param array{endpoint:string,keys:array{p256dh:string,auth:string}} $sub */
    public static function subscribe(int $userId, array $sub, string $userAgent, ?string $label = null): int
    {
        $hash = hash('sha256', (string) $sub['endpoint']);
        $existing = Database::selectOne('SELECT id FROM push_subscriptions WHERE endpoint_hash = ?', [$hash]);
        $fields = ['user_id' => $userId, 'endpoint' => (string) $sub['endpoint'], 'endpoint_hash' => $hash, 'p256dh' => (string) $sub['keys']['p256dh'], 'auth' => (string) $sub['keys']['auth'],
                   'user_agent' => mb_substr($userAgent, 0, 255), 'device_label' => mb_substr($label ?: self::labelFrom($userAgent), 0, 80), 'failures' => 0, 'disabled_at' => null];
        if ($existing !== null) {
            $sets = implode(', ', array_map(static fn(string $k): string => "`{$k}` = ?", array_keys($fields)));
            Database::execute("UPDATE push_subscriptions SET {$sets} WHERE id = ?", array_merge(array_values($fields), [(int) $existing['id']]));
            return (int) $existing['id'];
        }
        return Database::insert('push_subscriptions', $fields + ['created_at' => gmdate('Y-m-d H:i:s')]);
    }

    public static function unsubscribe(int $userId, int $id): void
    {
        Database::execute('DELETE FROM push_subscriptions WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    public static function unsubscribeEndpoint(int $userId, string $endpoint): void
    {
        Database::execute('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [$userId, hash('sha256', $endpoint)]);
    }

    /** @return list<array<string,mixed>> */
    public static function devices(int $userId): array
    {
        return Database::select('SELECT id, device_label, user_agent, last_success_at, failures, disabled_at, created_at FROM push_subscriptions WHERE user_id = ? ORDER BY created_at DESC', [$userId]);
    }

    /**
     * Envia um payload a todos os aparelhos ativos do usuário. @param array<string,mixed> $payload
     * @return array{sent:int,failed:int}
     */
    public static function sendToUser(int $userId, array $payload, ?int $onlySubscriptionId = null): array
    {
        if (!self::configured()) {
            return ['sent' => 0, 'failed' => 0];
        }
        $subs = Database::select('SELECT * FROM push_subscriptions WHERE user_id = ? AND disabled_at IS NULL' . ($onlySubscriptionId !== null ? ' AND id = ?' : ''), $onlySubscriptionId !== null ? [$userId, $onlySubscriptionId] : [$userId]);
        $sent = 0;
        $failed = 0;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        foreach ($subs as $sub) {
            $result = WebPush::send(['endpoint' => $sub['endpoint'], 'p256dh' => $sub['p256dh'], 'auth' => $sub['auth']], $json, (string) Config::get('vapid.public'), (string) Config::get('vapid.private'), (string) Config::get('vapid.subject', 'mailto:' . Config::get('mail.from_address', 'cofre@localhost')), 86400, ($payload['urgency'] ?? 'normal'));
            if ($result['ok']) {
                $sent++;
                Database::execute('UPDATE push_subscriptions SET last_success_at = ?, failures = 0 WHERE id = ?', [gmdate('Y-m-d H:i:s'), (int) $sub['id']]);
                continue;
            }
            $failed++;
            $failures = (int) $sub['failures'] + 1;
            $disable = $result['gone'] || $failures >= 3;
            Database::execute('UPDATE push_subscriptions SET failures = ?, disabled_at = ? WHERE id = ?', [$failures, $disable ? gmdate('Y-m-d H:i:s') : null, (int) $sub['id']]);
            Logger::warning('Push falhou', ['subscription' => $sub['id'], 'status' => $result['status'], 'error' => $result['error'], 'disabled' => $disable]);
            if ($disable) {
                self::notifyDisabled($userId, (string) ($sub['device_label'] ?: 'aparelho'));
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    private static function notifyDisabled(int $userId, string $device): void
    {
        $user = Database::selectOne('SELECT name, email FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return;
        }
        AlertService::create($userId, 'security', 'Notificações push desativadas em um aparelho', "O aparelho \"{$device}\" parou de receber push (3 falhas seguidas). Abra Conta → Notificações para reativar.", ['dedupe_key' => 'push-disabled:' . md5($device) . ':' . gmdate('Y-m-d'), 'channel' => 'none', 'url' => '/conta/notificacoes']);
        try {
            \App\Core\Mailer::send((string) $user['email'], (string) $user['name'], 'Push desativado em um aparelho', 'alert', ['title' => 'Notificações push desativadas', 'body' => "O aparelho \"{$device}\" parou de receber avisos push depois de 3 falhas seguidas. Os avisos continuam na Central e, se você ativou, por e-mail. Para religar, abra o app nesse aparelho em Conta → Notificações → Aparelhos.", 'url' => absolute_url('/conta/notificacoes'), 'color' => '#b91c1c', 'name' => $user['name']], 'notification', $userId);
        } catch (\Throwable $e) {
            Logger::error('Falha ao avisar push desativado', ['exception' => $e]);
        }
    }

    private static function labelFrom(string $ua): string
    {
        $os = match (true) { str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad', str_contains($ua, 'Android') => 'Android', str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Linux') => 'Linux', default => 'Aparelho' };
        $browser = match (true) { str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Firefox/') => 'Firefox', str_contains($ua, 'Safari/') => 'Safari', default => 'navegador' };
        return "{$os} · {$browser}";
    }
}
