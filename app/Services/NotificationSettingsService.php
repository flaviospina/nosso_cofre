<?php
// app/Services/NotificationSettingsService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Preferências de aviso por usuário (notification_settings.settings, JSON versão 2).
 * Nada é enviado por padrão: todos os tipos começam desligados; os canais (e-mail/push) refletem os consentimentos.
 */
final class NotificationSettingsService
{
    public const VERSION = 2;

    /** Paleta acessível (testada para daltonismo junto com ícone e texto). */
    public const COLORS = ['#15803d' => 'Verde', '#c2410c' => 'Laranja', '#ca8a04' => 'Amarelo', '#b91c1c' => 'Vermelho', '#1d4ed8' => 'Azul', '#6d28d9' => 'Roxo', '#0f766e' => 'Verde-azulado', '#475569' => 'Cinza'];
    public const SOUNDS = ['entrada' => 'Entrada', 'saida' => 'Saída', 'alerta' => 'Alerta', 'conquista' => 'Conquista', 'none' => 'Sem som'];
    public const CHANNELS = ['app' => 'Só no app', 'push' => 'Push', 'email' => 'E-mail', 'both' => 'Push e e-mail'];
    public const GROUP_MINUTES = [15 => '15 minutos', 60 => '1 hora', 180 => '3 horas', 360 => '6 horas', 720 => '12 horas', 1440 => '1 vez por dia'];

    /** Tipos de aviso (§5.1), com rótulo, ajuda e padrões de cor/som. */
    public const TYPES = [
        'income'          => ['label' => 'Dinheiro entrando', 'help' => 'Receita registrada ou prevista confirmada.', 'color' => '#15803d', 'sound' => 'entrada', 'severity' => 'success'],
        'expense'         => ['label' => 'Dinheiro saindo', 'help' => 'Despesa registrada e débito automático do dia.', 'color' => '#c2410c', 'sound' => 'saida', 'severity' => 'warning'],
        'due'             => ['label' => 'Conta a vencer', 'help' => 'Aviso alguns dias antes; no dia do vencimento fica vermelho.', 'color' => '#ca8a04', 'sound' => 'alerta', 'severity' => 'warning'],
        'overdue'         => ['label' => 'Conta atrasada', 'help' => 'Vencida e ainda não paga (com vibração).', 'color' => '#b91c1c', 'sound' => 'alerta', 'severity' => 'danger'],
        'budget'          => ['label' => 'Orçamento em X %', 'help' => 'Ao atingir cada limiar escolhido.', 'color' => '#c2410c', 'sound' => 'alerta', 'severity' => 'warning'],
        'subscription'    => ['label' => 'Assinatura duplicada ou mais cara', 'help' => 'Detectado pelo radar de assinaturas.', 'color' => '#b91c1c', 'sound' => 'alerta', 'severity' => 'danger'],
        'event'           => ['label' => 'Evento previsto grande', 'help' => 'PLR, 13º, IPVA… com antecedência em dias.', 'color' => '#1d4ed8', 'sound' => 'entrada', 'severity' => 'info'],
        'goal'            => ['label' => 'Meta batida / economia realizada', 'help' => 'Ao atingir uma meta ou concluir uma ação do plano.', 'color' => '#1d4ed8', 'sound' => 'conquista', 'severity' => 'success'],
        'cashflow'        => ['label' => 'Mês apertado à vista', 'help' => 'A previsão de caixa dos próximos 60 dias mostra saldo negativo.', 'color' => '#b91c1c', 'sound' => 'alerta', 'severity' => 'danger'],
        'member_activity' => ['label' => 'Lançamentos de outros membros', 'help' => 'Só em lar familiar: nunca, acima de um valor ou todos.', 'color' => '#0f766e', 'sound' => 'none', 'severity' => 'info'],
        'digest'          => ['label' => 'Resumo periódico', 'help' => 'Diário, semanal ou mensal, com o conteúdo que você escolher.', 'color' => '#0f766e', 'sound' => 'none', 'severity' => 'info'],
        'security'        => ['label' => 'Alertas de segurança', 'help' => 'Acesso de aparelho novo: sempre por e-mail; push opcional.', 'color' => '#b91c1c', 'sound' => 'alerta', 'severity' => 'danger'],
    ];
    public const DIGEST_CONTENTS = ['balance' => 'Saldo e projeção', 'budgets' => 'Orçamentos', 'due' => 'Contas a vencer', 'goals' => 'Metas e plano', 'subscriptions' => 'Radar de assinaturas'];

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        $types = [];
        foreach (self::TYPES as $key => $meta) {
            $types[$key] = ['enabled' => false, 'channel' => 'app', 'color' => $meta['color'], 'sound' => $meta['sound']];
        }
        $types['due']['days'] = [3, 0];
        $types['budget']['thresholds'] = [80, 100];
        $types['event']['days_before'] = 30;
        $types['member_activity']['mode'] = 'never';
        $types['member_activity']['min_amount'] = 200.0;
        $types['digest'] = ['enabled' => false, 'channel' => 'email', 'frequency' => 'weekly', 'weekday' => 1, 'day' => 1, 'time' => '08:00', 'contents' => ['balance', 'budgets', 'due', 'goals'], 'color' => '#0f766e', 'sound' => 'none'];
        $types['security'] = ['enabled' => true, 'channel' => 'email', 'color' => '#b91c1c', 'sound' => 'alerta'];
        return [
            'version'       => self::VERSION,
            'channels'      => ['email' => false, 'push' => false],
            'mode'          => 'immediate',
            'group_minutes' => 60,
            'group_time'    => '08:00',
            'daily_limit'   => 10,
            'min_amount'    => 0.0,
            'quiet'         => ['enabled' => false, 'start' => '22:00', 'end' => '07:00', 'days' => [1, 2, 3, 4, 5, 6, 7]],
            'days_off'      => [],
            'vibrate'       => true,
            'volume'        => 0.8,
            'muted_until'   => [],
            'types'         => $types,
        ];
    }

    /** Preferências efetivas do usuário (padrões + o que está gravado). @return array<string,mixed> */
    public static function load(int $userId): array
    {
        $row = Database::selectOne('SELECT settings FROM notification_settings WHERE user_id = ?', [$userId]);
        $stored = $row !== null ? (json_decode((string) $row['settings'], true) ?: []) : [];
        return self::merge(self::defaults(), $stored);
    }

    /** Valida/normaliza o que veio do formulário e grava. @param array<string,mixed> $input @return array<string,mixed> */
    public static function save(int $userId, array $input): array
    {
        $current = self::load($userId);
        $s = $current;
        $s['mode'] = in_array($input['mode'] ?? '', ['immediate', 'grouped', 'digest_only'], true) ? $input['mode'] : 'immediate';
        $gm = (int) ($input['group_minutes'] ?? $current['group_minutes'] ?? 60);
        $s['group_minutes'] = isset(self::GROUP_MINUTES[$gm]) ? $gm : 60;
        $s['group_time'] = self::time($input['group_time'] ?? '08:00', '08:00');
        $s['daily_limit'] = max(1, min(100, (int) ($input['daily_limit'] ?? 10)));
        $s['min_amount'] = max(0.0, (float) (\App\Core\Validator::parseMoney((string) ($input['min_amount'] ?? '0')) ?? 0));
        $s['quiet'] = [
            'enabled' => !empty($input['quiet_enabled']),
            'start'   => self::time($input['quiet_start'] ?? '22:00', '22:00'),
            'end'     => self::time($input['quiet_end'] ?? '07:00', '07:00'),
            'days'    => self::days($input['quiet_days'] ?? [1, 2, 3, 4, 5, 6, 7]),
        ];
        $s['days_off'] = self::days($input['days_off'] ?? []);
        $s['vibrate'] = !empty($input['vibrate']);
        $s['volume'] = max(0.0, min(1.0, (float) ($input['volume'] ?? 0.8)));
        foreach (self::TYPES as $key => $meta) {
            $in = is_array($input['types'][$key] ?? null) ? $input['types'][$key] : [];
            $t = $s['types'][$key];
            $t['enabled'] = $key === 'security' ? true : !empty($in['enabled']);
            $t['channel'] = isset(self::CHANNELS[$in['channel'] ?? '']) ? $in['channel'] : ($key === 'security' ? 'email' : 'app');
            $t['color'] = isset(self::COLORS[$in['color'] ?? '']) ? $in['color'] : $meta['color'];
            $t['sound'] = isset(self::SOUNDS[$in['sound'] ?? '']) ? $in['sound'] : $meta['sound'];
            if ($key === 'due') {
                $days = array_values(array_unique(array_map('intval', array_filter((array) ($in['days'] ?? [3, 0]), static fn($d): bool => in_array((int) $d, [0, 1, 3, 5, 7], true)))));
                rsort($days);
                $t['days'] = $days === [] ? [0] : $days;
            }
            if ($key === 'budget') {
                $th = array_values(array_unique(array_filter(array_map('intval', (array) ($in['thresholds'] ?? [80, 100])), static fn(int $v): bool => $v >= 10 && $v <= 200)));
                sort($th);
                $t['thresholds'] = $th === [] ? [80, 100] : $th;
            }
            if ($key === 'event') {
                $t['days_before'] = max(0, min(120, (int) ($in['days_before'] ?? 30)));
            }
            if ($key === 'member_activity') {
                $t['mode'] = in_array($in['mode'] ?? '', ['never', 'above', 'all'], true) ? $in['mode'] : 'never';
                $t['min_amount'] = max(0.0, (float) (\App\Core\Validator::parseMoney((string) ($in['min_amount'] ?? '200')) ?? 200));
                $t['enabled'] = $t['mode'] !== 'never';
            }
            if ($key === 'digest') {
                $t['channel'] = in_array($in['channel'] ?? '', ['email', 'push', 'both'], true) ? $in['channel'] : 'email';
                $t['frequency'] = in_array($in['frequency'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $in['frequency'] : 'weekly';
                $t['weekday'] = max(1, min(7, (int) ($in['weekday'] ?? 1)));
                $t['day'] = max(1, min(28, (int) ($in['day'] ?? 1)));
                $t['time'] = self::time($in['time'] ?? '08:00', '08:00');
                $contents = array_values(array_intersect(array_map('strval', (array) ($in['contents'] ?? [])), array_keys(self::DIGEST_CONTENTS)));
                $t['contents'] = $contents === [] ? ['balance', 'due'] : $contents;
            }
            if ($key === 'security') {
                $t['channel'] = !empty($in['push']) ? 'both' : 'email';
            }
            $s['types'][$key] = $t;
        }
        self::persist($userId, $s);
        return $s;
    }

    /** Silencia um tipo por N dias (Central de avisos). */
    public static function mute(int $userId, string $type, int $days = 7): void
    {
        if (!isset(self::TYPES[$type])) {
            return;
        }
        $s = self::load($userId);
        $s['muted_until'][$type] = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        self::persist($userId, $s);
    }

    public static function unmute(int $userId, string $type): void
    {
        $s = self::load($userId);
        unset($s['muted_until'][$type]);
        self::persist($userId, $s);
    }

    /** O tipo está ativo para o usuário neste momento (ligado, canal permitido pelos consentimentos e não silenciado)? */
    /** @param array<string,mixed> $settings @return array{enabled:bool,push:bool,email:bool,app:bool} */
    public static function effective(array $settings, string $type): array
    {
        $t = $settings['types'][$type] ?? null;
        if ($t === null) {
            return ['enabled' => false, 'push' => false, 'email' => false, 'app' => false];
        }
        $muted = isset($settings['muted_until'][$type]) && strtotime((string) $settings['muted_until'][$type] . ' UTC') > time();
        $enabled = !empty($t['enabled']) && !$muted;
        $channel = (string) ($t['channel'] ?? 'app');
        $push = $enabled && in_array($channel, ['push', 'both'], true) && !empty($settings['channels']['push']);
        $email = $enabled && in_array($channel, ['email', 'both'], true) && (!empty($settings['channels']['email']) || $type === 'security');
        return ['enabled' => $enabled, 'push' => $push, 'email' => $email, 'app' => $enabled];
    }

    /** @param array<string,mixed> $settings */
    public static function persist(int $userId, array $settings): void
    {
        $settings['version'] = self::VERSION;
        Database::execute(
            'INSERT INTO notification_settings (user_id, settings, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE settings = VALUES(settings)',
            [$userId, json_encode($settings, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s')]
        );
    }

    // --- internos ---

    /** @param array<string,mixed> $base @param array<string,mixed> $over @return array<string,mixed> */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($base[$k])) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private static function time(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $default;
    }

    /** @return list<int> */
    private static function days(mixed $value): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', (array) $value), static fn(int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);
        return $days;
    }
}
