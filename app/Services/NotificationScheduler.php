<?php
// app/Services/NotificationScheduler.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Mailer;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Decide, a cada execução do cron, o que avisar para quem (§5.2):
 *  1) detecta eventos (dinheiro entrando/saindo, contas a vencer/atrasadas, orçamento, radar, eventos previstos, metas,
 *     previsão de caixa, lançamentos de outros membros, resumos) e cria avisos com deduplicação;
 *  2) entrega os avisos pendentes respeitando canais, modo (imediato/agrupado/só resumo), horário silencioso,
 *     dias sem avisos, limite diário de push e valor mínimo. Fora da janela, o aviso espera (scheduled_for).
 */
final class NotificationScheduler
{
    /** @return array<string,int> */
    public static function run(?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $summary = ['usuarios' => 0, 'criados' => 0, 'push' => 0, 'email' => 0, 'adiados' => 0];
        $lastRun = Database::scalar("SELECT MAX(started_at) FROM cron_runs WHERE task = 'notifications'");
        $since = $lastRun !== null ? new DateTimeImmutable((string) $lastRun . ' UTC') : $now->modify('-1 day');
        $users = Database::select(
            "SELECT u.id, u.name, u.email, u.timezone, m.household_id, h.type AS household_type FROM users u
               JOIN household_members m ON m.user_id = u.id AND m.left_at IS NULL
               JOIN households h ON h.id = m.household_id AND h.status = 'active'
              WHERE u.status = 'active' AND u.email_verified_at IS NOT NULL"
        );
        foreach ($users as $u) {
            try {
                $settings = NotificationSettingsService::load((int) $u['id']);
                $summary['usuarios']++;
                $summary['criados'] += self::detect($u, $settings, $now, $since);
            } catch (\Throwable $e) {
                Logger::error('Falha ao detectar avisos', ['user' => $u['id'], 'exception' => $e]);
            }
        }
        $delivered = self::deliver($now);
        $summary['push'] += $delivered['push'];
        $summary['email'] += $delivered['email'];
        $summary['adiados'] += $delivered['adiados'];
        Database::execute('INSERT INTO cron_runs (task, started_at, finished_at, status, message) VALUES (?, ?, UTC_TIMESTAMP(), ?, ?)', ['notifications', $now->format('Y-m-d H:i:s'), 'ok', json_encode($summary)]);
        return $summary;
    }

    // ------------------------------------------------------------------ detecção

    /** @param array<string,mixed> $u @param array<string,mixed> $settings */
    public static function detect(array $u, array $settings, DateTimeImmutable $now, DateTimeImmutable $since): int
    {
        $userId = (int) $u['id'];
        $householdId = (int) $u['household_id'];
        $tz = new DateTimeZone((string) ($u['timezone'] ?: 'America/Sao_Paulo'));
        $local = $now->setTimezone($tz);
        $today = $local->setTime(0, 0);
        $todayStr = $today->format('Y-m-d');
        $created = 0;
        $on = static fn(string $type): array => NotificationSettingsService::effective($settings, $type);
        $channel = static function (array $eff): string {
            return $eff['push'] && $eff['email'] ? 'both' : ($eff['push'] ? 'push' : ($eff['email'] ? 'email' : 'none'));
        };
        $minAmount = (float) ($settings['min_amount'] ?? 0);
        $sinceStr = $since->format('Y-m-d H:i:s');

        // 1) Dinheiro entrando / 2) saindo: lançamentos criados desde a última execução (pagos ou pendentes, não agendados)
        foreach (['income', 'expense'] as $type) {
            $eff = $on($type);
            if (!$eff['enabled']) {
                continue;
            }
            $rows = Database::select(
                "SELECT t.id, t.amount, t.description, t.date, t.status, t.auto_debit, t.created_by, u2.name AS by_name FROM transactions t LEFT JOIN users u2 ON u2.id = t.created_by
                  WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.type = ? AND t.status <> 'scheduled' AND t.created_at > ? AND t.amount >= ?
                    AND NOT (t.is_private = 1 AND t.created_by <> ?) ORDER BY t.id LIMIT 50",
                [$householdId, $type, $sinceStr, $minAmount, $userId]
            );
            foreach ($rows as $t) {
                $title = $type === 'income' ? 'Dinheiro entrando: ' . money($t['amount']) : 'Dinheiro saindo: ' . money($t['amount']);
                $body = $t['description'] . ' · ' . date_br($t['date']) . ($t['by_name'] && (int) $t['created_by'] !== $userId ? ' · por ' . $t['by_name'] : '') . ($t['status'] === 'pending' ? ' (pendente)' : '');
                $created += self::emit($userId, $householdId, $settings, $type, $title, $body, "{$type}:tx:{$t['id']}", '/lancamentos/' . $t['id'] . '/editar', $channel($eff)) ? 1 : 0;
            }
            // Débito automático do dia
            if ($type === 'expense') {
                foreach (Database::select("SELECT id, amount, description FROM transactions WHERE household_id = ? AND deleted_at IS NULL AND type = 'expense' AND auto_debit = 1 AND status <> 'paid' AND date = ? AND amount >= ?", [$householdId, $todayStr, $minAmount]) as $t) {
                    $created += self::emit($userId, $householdId, $settings, 'expense', 'Débito automático hoje: ' . money($t['amount']), $t['description'] . ' sai da conta hoje. Confira o saldo.', "autodebit:tx:{$t['id']}", '/recorrencias', $channel($eff)) ? 1 : 0;
                }
            }
        }

        // 3) Conta a vencer (dias escolhidos) e 4) atrasada
        $eff = $on('due');
        if ($eff['enabled']) {
            foreach ((array) ($settings['types']['due']['days'] ?? [3, 0]) as $d) {
                $target = $today->modify("+{$d} days")->format('Y-m-d');
                foreach (Database::select("SELECT id, amount, description, date FROM transactions WHERE household_id = ? AND deleted_at IS NULL AND type = 'expense' AND status <> 'paid' AND date = ? AND amount >= ?", [$householdId, $target, $minAmount]) as $t) {
                    $title = $d === 0 ? 'Vence hoje: ' . $t['description'] : "Vence em {$d} dia(s): " . $t['description'];
                    $created += self::emit($userId, $householdId, $settings, 'due', $title, money($t['amount']) . ' · ' . date_br($t['date']), "due:tx:{$t['id']}:d{$d}", '/lancamentos/' . $t['id'] . '/editar', $channel($eff), ['color' => $d === 0 ? '#b91c1c' : null, 'payload' => ['transaction_id' => (int) $t['id']]]) ? 1 : 0;
                }
            }
        }
        $eff = $on('overdue');
        if ($eff['enabled']) {
            foreach (Database::select("SELECT id, amount, description, date FROM transactions WHERE household_id = ? AND deleted_at IS NULL AND type = 'expense' AND status <> 'paid' AND date < ? AND date >= ? AND amount >= ?", [$householdId, $todayStr, $today->modify('-60 days')->format('Y-m-d'), $minAmount]) as $t) {
                $created += self::emit($userId, $householdId, $settings, 'overdue', 'Conta atrasada: ' . $t['description'], money($t['amount']) . ' venceu em ' . date_br($t['date']) . '. Pague ou marque como paga.', "overdue:tx:{$t['id']}:w" . $today->format('oW'), '/lancamentos/' . $t['id'] . '/editar', $channel($eff), ['payload' => ['transaction_id' => (int) $t['id'], 'vibrate' => true]]) ? 1 : 0;
            }
        }

        // 5) Orçamento em X %
        $eff = $on('budget');
        if ($eff['enabled']) {
            $thresholds = (array) ($settings['types']['budget']['thresholds'] ?? [80, 100]);
            foreach (BudgetService::overview($householdId, $today->format('Y-m-01'), $today) as $b) {
                foreach ($thresholds as $th) {
                    if ((int) $b['pct'] >= (int) $th) {
                        $title = ($b['pct'] >= 100 ? 'Orçamento estourado: ' : "Orçamento em {$th}%: ") . $b['category_name'];
                        $body = money($b['spent']) . ' de ' . money($b['limit_amount']) . ' (' . (int) $b['pct'] . '%)' . ($b['burst_day'] !== null ? " · neste ritmo estoura no dia {$b['burst_day']}" : '');
                        $created += self::emit($userId, $householdId, $settings, 'budget', $title, $body, "budget:{$b['id']}:{$th}:" . $today->format('Y-m'), '/orcamento?mes=' . $today->format('Y-m'), $channel($eff), ['color' => $b['pct'] >= 100 ? '#b91c1c' : null, 'severity' => $b['pct'] >= 100 ? 'danger' : 'warning']) ? 1 : 0;
                    }
                }
            }
        }

        // 6) Duplicidade / aumento de assinatura
        $eff = $on('subscription');
        if ($eff['enabled']) {
            $radar = SubscriptionService::radar($householdId, $today);
            foreach ($radar['items'] as $item) {
                foreach ($item['flags'] as $flag) {
                    if ($flag['kind'] === 'unused') {
                        continue;
                    }
                    $created += self::emit($userId, $householdId, $settings, 'subscription', $flag['label'] . ': ' . $item['description'], $flag['detail'], "sub:{$item['id']}:{$flag['kind']}:" . $today->format('Y-m'), '/assinaturas', $channel($eff)) ? 1 : 0;
                }
            }
        }

        // 7) Evento previsto grande
        $eff = $on('event');
        if ($eff['enabled']) {
            $daysBefore = (int) ($settings['types']['event']['days_before'] ?? 30);
            foreach (RecurrenceService::upcomingEvents($householdId, $today, 366) as $ev) {
                $lead = $ev['notify_days_before'] !== null ? (int) $ev['notify_days_before'] : $daysBefore;
                if ((int) $ev['days_until'] <= $lead) {
                    $body = money($ev['expected_amount']) . ' previsto para ' . date_br($ev['next_date']) . ' (em ' . (int) $ev['days_until'] . ' dias).' . ($ev['suggestions'] !== [] ? ' Sugestão: ' . $ev['suggestions'][0]['title'] . '.' : '');
                    $created += self::emit($userId, $householdId, $settings, 'event', 'Evento previsto: ' . $ev['description'], $body, "event:{$ev['id']}:" . substr((string) $ev['next_date'], 0, 4), '/recorrencias', $channel($eff)) ? 1 : 0;
                }
            }
        }

        // 8) Meta batida / ação concluída
        $eff = $on('goal');
        if ($eff['enabled']) {
            foreach (Database::select("SELECT id, name, target_amount FROM goals WHERE household_id = ? AND deleted_at IS NULL AND status = 'done' AND achieved_at > ?", [$householdId, $sinceStr]) as $g) {
                $created += self::emit($userId, $householdId, $settings, 'goal', '🎉 Meta batida: ' . $g['name'], 'Vocês guardaram ' . money($g['target_amount']) . '. Parabéns!', "goal:{$g['id']}", '/metas', $channel($eff)) ? 1 : 0;
            }
            foreach (Database::select("SELECT id, title, measured_saving_month FROM savings_actions WHERE household_id = ? AND deleted_at IS NULL AND status = 'done' AND done_at >= ? AND updated_at > ?", [$householdId, $today->modify('-2 days')->format('Y-m-d'), $sinceStr]) as $a) {
                $created += self::emit($userId, $householdId, $settings, 'goal', 'Ação concluída: ' . $a['title'], $a['measured_saving_month'] !== null ? 'Economia medida neste mês: ' . money($a['measured_saving_month']) . '.' : 'Mais um passo do plano de ação feito.', "action:{$a['id']}", '/plano', $channel($eff)) ? 1 : 0;
            }
        }

        // 9) Lançamentos de outros membros (lar familiar)
        $eff = $on('member_activity');
        if ($eff['enabled'] && $u['household_type'] === 'family') {
            $mode = (string) ($settings['types']['member_activity']['mode'] ?? 'never');
            $min = $mode === 'above' ? (float) ($settings['types']['member_activity']['min_amount'] ?? 200) : 0.0;
            foreach (Database::select(
                "SELECT t.id, t.amount, t.description, t.type, u2.name AS by_name FROM transactions t JOIN users u2 ON u2.id = t.created_by
                  WHERE t.household_id = ? AND t.deleted_at IS NULL AND t.created_by <> ? AND t.is_private = 0 AND t.status <> 'scheduled' AND t.created_at > ? AND t.amount >= ? ORDER BY t.id LIMIT 50",
                [$householdId, $userId, $sinceStr, $min]
            ) as $t) {
                $created += self::emit($userId, $householdId, $settings, 'member_activity', $t['by_name'] . ' registrou ' . money($t['amount']), $t['description'] . ' (' . ($t['type'] === 'income' ? 'receita' : ($t['type'] === 'expense' ? 'despesa' : 'transferência')) . ')', "member:tx:{$t['id']}", '/lancamentos', $channel($eff)) ? 1 : 0;
            }
        }

        // Previsão de caixa: mês apertado à vista
        $eff = $on('cashflow');
        if ($eff['enabled']) {
            $forecast = CashflowService::forecast($householdId, $today, 60);
            if ($forecast['first_negative'] !== null) {
                $created += self::emit($userId, $householdId, $settings, 'cashflow', 'Mês apertado à vista: saldo negativo em ' . date_br($forecast['first_negative']), 'Se nada mudar, o saldo em conta chega a ' . money($forecast['lowest']['balance']) . ' em ' . date_br($forecast['lowest']['date']) . '. Veja o que adiar ou antecipar.', 'cashflow:' . $forecast['first_negative'] . ':w' . $today->format('oW'), '/previsao', $channel($eff)) ? 1 : 0;
            }
        }

        // 10) Resumo periódico
        $eff = $on('digest');
        if ($eff['enabled']) {
            $d = $settings['types']['digest'];
            $dueTime = (string) ($d['time'] ?? '08:00');
            $isDay = match ($d['frequency'] ?? 'weekly') {
                'daily'   => true,
                'monthly' => (int) $today->format('j') === (int) ($d['day'] ?? 1),
                default   => (int) $today->format('N') === (int) ($d['weekday'] ?? 1),
            };
            if ($isDay && $local->format('H:i') >= $dueTime) {
                $key = 'digest:' . ($d['frequency'] ?? 'weekly') . ':' . $todayStr;
                if (Database::scalar('SELECT id FROM alerts WHERE user_id = ? AND dedupe_key = ?', [$userId, $key]) === null) {
                    [$title, $body, $payload] = self::buildDigest($householdId, $today, (array) ($d['contents'] ?? []));
                    $created += self::emit($userId, $householdId, $settings, 'digest', $title, $body, $key, '/painel', $channel($eff), ['payload' => $payload]) ? 1 : 0;
                }
            }
        }
        return $created;
    }

    /** Cria o aviso (com canal e agendamento) se ainda não existe. @param array<string,mixed> $extra */
    private static function emit(int $userId, int $householdId, array $settings, string $type, string $title, string $body, string $dedupe, string $url, string $channel, array $extra = []): bool
    {
        $t = $settings['types'][$type] ?? [];
        $opts = [
            'household_id' => $householdId, 'dedupe_key' => $dedupe, 'url' => $url, 'channel' => $channel,
            'color' => $extra['color'] ?? ($t['color'] ?? null), 'sound' => $t['sound'] ?? null,
            'severity' => $extra['severity'] ?? null, 'payload' => $extra['payload'] ?? null,
        ];
        if ($opts['color'] === null) {
            unset($opts['color']);
        }
        if ($opts['severity'] === null) {
            unset($opts['severity']);
        }
        if ($opts['payload'] === null) {
            unset($opts['payload']);
        }
        // Modo "só resumo": tudo fica na Central e no resumo, sem push/e-mail imediatos (exceto segurança e resumo)
        if (($settings['mode'] ?? 'immediate') === 'digest_only' && !in_array($type, ['digest', 'security'], true)) {
            $opts['channel'] = 'none';
        }
        return AlertService::create($userId, $type, $title, $body, $opts) !== null;
    }

    /** @param list<string> $contents @return array{0:string,1:string,2:array<string,mixed>} */
    public static function buildDigest(int $householdId, DateTimeImmutable $today, array $contents): array
    {
        $lines = [];
        $payload = [];
        $month = $today->format('Y-m-01');
        if (in_array('balance', $contents, true)) {
            $data = InsightsService::dashboard($householdId, $month, null, $today);
            $lines[] = 'Saldo do mês: ' . money($data['month']['balance']) . ' (projetado ' . money($data['month']['projected']) . '). Score: ' . $data['score']['total'] . '/100.';
            $payload['balance'] = $data['month'];
        }
        if (in_array('due', $contents, true)) {
            $due = Database::select("SELECT description, amount, date FROM transactions WHERE household_id = ? AND deleted_at IS NULL AND type = 'expense' AND status <> 'paid' AND date <= ? ORDER BY date LIMIT 6", [$householdId, $today->modify('+7 days')->format('Y-m-d')]);
            $lines[] = $due === [] ? 'Nada a vencer nos próximos 7 dias.' : 'A vencer em 7 dias: ' . implode('; ', array_map(static fn(array $t): string => $t['description'] . ' ' . money($t['amount']) . ' (' . date_br($t['date']) . ')', $due)) . '.';
        }
        if (in_array('budgets', $contents, true)) {
            $top = array_slice(BudgetService::overview($householdId, $month, $today), 0, 3);
            $lines[] = $top === [] ? 'Sem orçamentos no mês.' : 'Orçamentos: ' . implode('; ', array_map(static fn(array $b): string => $b['category_name'] . ' ' . $b['pct'] . '%', $top)) . '.';
        }
        if (in_array('goals', $contents, true)) {
            $goals = GoalService::withProgress($householdId, $today, false);
            $lines[] = $goals === [] ? 'Sem metas ativas.' : 'Metas: ' . implode('; ', array_map(static fn(array $g): string => $g['name'] . ' ' . $g['pct'] . '%', array_slice($goals, 0, 3))) . '.';
        }
        if (in_array('subscriptions', $contents, true)) {
            $radar = SubscriptionService::radar($householdId, $today);
            $lines[] = 'Assinaturas: ' . money($radar['monthly_total']) . '/mês' . ($radar['alerts'] > 0 ? ", {$radar['alerts']} alerta(s)" : ', sem alertas') . '.';
        }
        return ['Resumo de ' . date_br($today->format('Y-m-d')), implode("\n", $lines), $payload];
    }

    // ------------------------------------------------------------------ entrega

    /** Entrega avisos pendentes (sent_at nulo) respeitando janelas, agrupamento e limite diário. @return array{push:int,email:int,adiados:int} */
    public static function deliver(DateTimeImmutable $now): array
    {
        $out = ['push' => 0, 'email' => 0, 'adiados' => 0];
        $pending = Database::select(
            "SELECT a.*, u.name AS user_name, u.email AS user_email, u.timezone FROM alerts a JOIN users u ON u.id = a.user_id
              WHERE a.sent_at IS NULL AND a.channel <> 'none' AND (a.scheduled_for IS NULL OR a.scheduled_for <= ?) ORDER BY a.user_id, a.id LIMIT 300",
            [$now->format('Y-m-d H:i:s')]
        );
        $byUser = [];
        foreach ($pending as $a) {
            $byUser[(int) $a['user_id']][] = $a;
        }
        foreach ($byUser as $userId => $alerts) {
            $settings = NotificationSettingsService::load($userId);
            $tz = new DateTimeZone((string) ($alerts[0]['timezone'] ?: 'America/Sao_Paulo'));
            $local = $now->setTimezone($tz);
            $window = self::nextWindow($settings, $local);
            if ($window !== null) {
                // Horário silencioso / dia sem avisos: adia tudo (menos segurança) para o fim da janela
                foreach ($alerts as $a) {
                    if ($a['type'] === 'security') {
                        continue;
                    }
                    Database::execute('UPDATE alerts SET scheduled_for = ? WHERE id = ?', [$window->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), (int) $a['id']]);
                    $out['adiados']++;
                }
                $alerts = array_values(array_filter($alerts, static fn(array $a): bool => $a['type'] === 'security'));
            }
            if ($alerts === []) {
                continue;
            }
            // Agrupamento: junta os avisos num push/e-mail só, a cada N minutos ou 1×/dia na hora escolhida
            $mode = (string) ($settings['mode'] ?? 'immediate');
            $groupable = array_values(array_filter($alerts, static fn(array $a): bool => !in_array($a['type'], ['security', 'digest'], true)));
            $single = array_values(array_filter($alerts, static fn(array $a): bool => in_array($a['type'], ['security', 'digest'], true)));
            if ($mode === 'grouped' && $groupable !== []) {
                $minutes = (int) ($settings['group_minutes'] ?? 60);
                $lastGroup = Database::scalar("SELECT MAX(sent_at) FROM alerts WHERE user_id = ? AND type NOT IN ('security','digest') AND sent_at IS NOT NULL AND channel <> 'none'", [$userId]);
                $ready = $minutes >= 1440 ? $local->format('H:i') >= (string) ($settings['group_time'] ?? '08:00') && ($lastGroup === null || substr((string) $lastGroup, 0, 10) !== $now->format('Y-m-d')) : ($lastGroup === null || strtotime((string) $lastGroup . ' UTC') <= $now->getTimestamp() - $minutes * 60);
                if (!$ready) {
                    $out['adiados'] += count($groupable);
                    $groupable = [];
                } else {
                    $groupable = [self::merge($groupable, $userId)];
                }
            }
            $dailyLimit = (int) ($settings['daily_limit'] ?? 10);
            $pushedToday = (int) Database::scalar("SELECT COUNT(*) FROM alerts WHERE user_id = ? AND sent_at >= ? AND channel IN ('push','both')", [$userId, $now->format('Y-m-d 00:00:00')]);
            foreach (array_merge($single, $groupable) as $a) {
                $ids = $a['ids'] ?? [(int) $a['id']];
                $wantsPush = in_array($a['channel'], ['push', 'both'], true);
                $wantsEmail = in_array($a['channel'], ['email', 'both'], true);
                if ($wantsPush && $pushedToday >= $dailyLimit && $a['type'] !== 'security') {
                    $wantsPush = false;   // excedente fica só na Central (e por e-mail, se pedido)
                }
                if ($wantsPush) {
                    $r = PushService::sendToUser($userId, self::pushPayload($a, $userId, $settings));
                    $out['push'] += $r['sent'] > 0 ? 1 : 0;
                    $pushedToday += $r['sent'] > 0 ? 1 : 0;
                }
                if ($wantsEmail) {
                    try {
                        Mailer::send((string) $alerts[0]['user_email'], (string) $alerts[0]['user_name'], mb_substr((string) $a['title'], 0, 150), 'alert', ['title' => $a['title'], 'body' => (string) $a['body'], 'url' => absolute_url((string) ($a['url'] ?: '/painel')), 'color' => (string) ($a['color'] ?: '#0f766e'), 'name' => $alerts[0]['user_name']], 'notification', $userId);
                        $out['email']++;
                    } catch (\Throwable $e) {
                        Logger::error('Falha ao enviar e-mail de aviso', ['alert' => $ids, 'exception' => $e]);
                    }
                }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                Database::execute("UPDATE alerts SET sent_at = ? WHERE id IN ({$placeholders})", array_merge([$now->format('Y-m-d H:i:s')], $ids));
            }
        }
        return $out;
    }

    /** Fim da janela silenciosa em curso (hora local) ou null quando pode enviar agora. @param array<string,mixed> $settings */
    public static function nextWindow(array $settings, DateTimeImmutable $local): ?DateTimeImmutable
    {
        $weekday = (int) $local->format('N');
        if (in_array($weekday, (array) ($settings['days_off'] ?? []), true)) {
            $next = $local->modify('tomorrow');
            while (in_array((int) $next->format('N'), (array) $settings['days_off'], true)) {
                $next = $next->modify('+1 day');
            }
            return $next->setTime(8, 0);
        }
        $q = $settings['quiet'] ?? [];
        if (empty($q['enabled']) || !in_array($weekday, (array) ($q['days'] ?? []), true)) {
            return null;
        }
        $time = $local->format('H:i');
        $start = (string) $q['start'];
        $end = (string) $q['end'];
        if ($start <= $end) {
            return $time >= $start && $time < $end ? $local->setTime((int) substr($end, 0, 2), (int) substr($end, 3, 2)) : null;
        }
        // atravessa a meia-noite (ex.: 22:00 → 07:00)
        if ($time >= $start) {
            return $local->modify('tomorrow')->setTime((int) substr($end, 0, 2), (int) substr($end, 3, 2));
        }
        return $time < $end ? $local->setTime((int) substr($end, 0, 2), (int) substr($end, 3, 2)) : null;
    }

    /** Junta vários avisos num só (agrupado). @param list<array<string,mixed>> $alerts @return array<string,mixed> */
    private static function merge(array $alerts, int $userId): array
    {
        $first = $alerts[0];
        $channels = array_column($alerts, 'channel');
        $channel = in_array('both', $channels, true) || (in_array('push', $channels, true) && in_array('email', $channels, true)) ? 'both' : $channels[0];
        return [
            'id' => (int) $first['id'], 'ids' => array_map(static fn(array $a): int => (int) $a['id'], $alerts), 'type' => 'grouped',
            'title' => count($alerts) === 1 ? $first['title'] : count($alerts) . ' avisos do Nosso Cofre',
            'body' => implode("\n", array_map(static fn(array $a): string => '• ' . $a['title'], array_slice($alerts, 0, 8))) . (count($alerts) > 8 ? "\n…" : ''),
            'url' => '/avisos', 'color' => $first['color'], 'sound' => $first['sound'], 'channel' => $channel, 'payload' => null, 'severity' => $first['severity'],
        ];
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $settings @return array<string,mixed> */
    public static function pushPayload(array $a, int $userId, array $settings): array
    {
        $payload = is_string($a['payload'] ?? null) ? (json_decode((string) $a['payload'], true) ?: []) : ((array) ($a['payload'] ?? []));
        $actions = [['action' => 'open', 'title' => 'Ver']];
        $data = [];
        if (!empty($payload['transaction_id']) && in_array($a['type'], ['due', 'overdue'], true)) {
            $actions[] = ['action' => 'pay', 'title' => 'Marcar como pago'];
            $data['payUrl'] = absolute_url('/avisos/acao/' . AlertService::actionToken($userId, (int) $payload['transaction_id']));
        }
        return [
            'title' => (string) $a['title'], 'body' => (string) $a['body'], 'url' => absolute_url((string) ($a['url'] ?: '/avisos')),
            'tag' => 'nc-' . $a['type'], 'renotify' => true, 'type' => $a['type'], 'color' => $a['color'], 'sound' => $a['sound'],
            'vibrate' => !empty($settings['vibrate']) && ($a['type'] === 'overdue' || !empty($payload['vibrate'])) ? [200, 100, 200] : (!empty($settings['vibrate']) ? [100] : []),
            'urgency' => in_array($a['severity'], ['danger'], true) ? 'high' : 'normal', 'actions' => $actions, 'data' => $data, 'alertId' => (int) $a['id'],
        ];
    }
}
