<?php
// tests/NotificationTest.php — preferências, janelas silenciosas, Web Push (cifra) e o NotificationScheduler (integração)
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

use App\Core\Database;
use App\Core\WebPush;
use App\Services\AlertService;
use App\Services\NotificationScheduler;
use App\Services\NotificationSettingsService as NS;

function test_notification_settings_defaults_and_normalization(): void
{
    $d = NS::defaults();
    assert_false($d['types']['due']['enabled'], 'nada ligado por padrão');
    assert_true($d['types']['security']['enabled'], 'segurança sempre ligada (e-mail)');
    assert_same([3, 0], $d['types']['due']['days']);
    if (!db_available('notification_settings')) { return; }
    $f = privacy_fixture();
    $u = (int) $f['owner'];
    $saved = NS::save($u, [
        'mode' => 'grouped', 'group_minutes' => '999', 'daily_limit' => '500', 'min_amount' => '50,00', 'quiet_enabled' => '1', 'quiet_start' => '23:00', 'quiet_end' => '06:30', 'quiet_days' => ['1', '7', '9'],
        'days_off' => ['6'], 'vibrate' => '1', 'volume' => '1.7',
        'types' => ['due' => ['enabled' => '1', 'channel' => 'both', 'color' => '#1d4ed8', 'sound' => 'conquista', 'days' => ['7', '1', '99']], 'budget' => ['enabled' => '1', 'thresholds' => ['100', '80', '5']], 'security' => ['push' => '1'], 'member_activity' => ['mode' => 'above', 'min_amount' => '300,00'], 'digest' => ['enabled' => '1', 'frequency' => 'monthly', 'day' => '40', 'time' => '25:00', 'contents' => ['due', 'xyz']]],
    ]);
    assert_same('grouped', $saved['mode']);
    assert_same(60, $saved['group_minutes'], 'valor inválido cai no padrão');
    assert_same(100, $saved['daily_limit'], 'limite máximo 100');
    assert_same(50.0, $saved['min_amount']);
    assert_same([1, 7], $saved['quiet']['days']);
    assert_same([6], $saved['days_off']);
    assert_same(1.0, $saved['volume']);
    assert_same([7, 1], $saved['types']['due']['days']);
    assert_same([80, 100], $saved['types']['budget']['thresholds']);
    assert_same('both', $saved['types']['security']['channel']);
    assert_true($saved['types']['member_activity']['enabled']);
    assert_same(300.0, $saved['types']['member_activity']['min_amount']);
    assert_same(28, $saved['types']['digest']['day']);
    assert_same('08:00', $saved['types']['digest']['time']);
    assert_same(['due'], $saved['types']['digest']['contents']);
    // Canais dependem dos consentimentos: sem consentimento de push, "both" vira só e-mail... e sem e-mail, nada
    $eff = NS::effective($saved, 'due');
    assert_true($eff['enabled']); assert_false($eff['push']); assert_false($eff['email']);
    $saved['channels'] = ['email' => true, 'push' => true];
    $eff = NS::effective($saved, 'due');
    assert_true($eff['push']); assert_true($eff['email']);
    NS::mute($u, 'due', 7);
    assert_false(NS::effective(NS::load($u), 'due')['enabled'], 'silenciado por 7 dias');
    NS::unmute($u, 'due');
    assert_true(NS::effective(NS::load($u), 'due')['enabled']);
}

function test_notification_quiet_window(): void
{
    $s = NS::defaults();
    $s['quiet'] = ['enabled' => true, 'start' => '22:00', 'end' => '07:00', 'days' => [1, 2, 3, 4, 5, 6, 7]];
    $tz = new DateTimeZone('America/Sao_Paulo');
    $at = static fn(string $s): DateTimeImmutable => new DateTimeImmutable($s, $tz);
    assert_same('2026-09-19 07:00', NotificationScheduler::nextWindow($s, $at('2026-09-18 23:30'))->format('Y-m-d H:i'), 'à noite espera o fim da janela (dia seguinte)');
    assert_same('2026-09-18 07:00', NotificationScheduler::nextWindow($s, $at('2026-09-18 05:00'))->format('Y-m-d H:i'));
    assert_null(NotificationScheduler::nextWindow($s, $at('2026-09-18 12:00')), 'meio-dia pode enviar');
    $s['quiet']['days'] = [1, 2, 3, 4, 5];
    assert_null(NotificationScheduler::nextWindow($s, $at('2026-09-19 23:30')), 'sábado sem horário silencioso');
    $s['days_off'] = [6, 7];
    assert_same('2026-09-21 08:00', NotificationScheduler::nextWindow($s, $at('2026-09-19 12:00'))->format('Y-m-d H:i'), 'fim de semana sem avisos → segunda 8h');
}

function test_webpush_vapid_and_encryption_roundtrip(): void
{
    $keys = WebPush::generateVapidKeys();
    assert_same(65, strlen(WebPush::b64decode($keys['public'])));
    assert_same(32, strlen(WebPush::b64decode($keys['private'])));
    $jwt = WebPush::vapidJwt('https://fcm.googleapis.com', 'mailto:x@y.z', $keys['private'], $keys['public']);
    [$h, $c, $sig] = explode('.', $jwt);
    assert_same('ES256', json_decode(WebPush::b64decode($h), true)['alg']);
    assert_same('https://fcm.googleapis.com', json_decode(WebPush::b64decode($c), true)['aud']);
    assert_same(64, strlen(WebPush::b64decode($sig)), 'assinatura JOSE r||s');
    // Navegador simulado: par P-256 + auth de 16 bytes; o servidor cifra e o "navegador" decifra
    $recv = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($recv, $pem);
    $d = openssl_pkey_get_details($recv);
    $p256dh = WebPush::b64url("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT));
    $auth = WebPush::b64url(random_bytes(16));
    $payload = json_encode(['title' => 'Conta a vencer', 'body' => 'Água R$ 120,00 · ação, coração']);
    $body = WebPush::encrypt($payload, $p256dh, $auth);
    assert_same(86 + strlen($payload) + 1 + 16, strlen($body), 'salt 16 + rs 4 + idlen 1 + key 65 + cifra + tag');
    assert_same(65, ord($body[20]));
    assert_same($payload, WebPush::decrypt($body, $pem, $auth));
    assert_throws(RuntimeException::class, static fn() => WebPush::encrypt('x', 'abc', $auth), 'chave inválida');
}

function test_scheduler_detects_delivers_and_dedupes(): void
{
    if (!db_available('alerts')) { return; }
    $f = privacy_fixture(true);
    $h = (int) $f['household']; $owner = (int) $f['owner']; $member = (int) $f['member']; $acc = (int) $f['account'];
    $now = new DateTimeImmutable('2026-09-18 15:00:00', new DateTimeZone('UTC'));   // 12:00 em São Paulo
    Database::execute('UPDATE users SET timezone = ? WHERE id IN (?, ?)', ['America/Sao_Paulo', $owner, $member]);
    $ts = gmdate('Y-m-d H:i:s');
    Database::insert('transactions', ['household_id' => $h, 'account_id' => $acc, 'created_by' => $member, 'responsible_user_id' => $owner, 'type' => 'expense', 'amount' => '120.00', 'date' => '2026-09-21', 'description' => 'Água', 'status' => 'pending', 'created_at' => $ts]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => $acc, 'created_by' => $member, 'responsible_user_id' => $owner, 'type' => 'expense', 'amount' => '80.00', 'date' => '2026-09-10', 'description' => 'Luz', 'status' => 'pending', 'created_at' => $ts]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => $acc, 'created_by' => $member, 'responsible_user_id' => $owner, 'type' => 'income', 'amount' => '3000.00', 'date' => '2026-09-18', 'description' => 'Salário', 'status' => 'paid', 'created_at' => $ts]);
    Database::insert('budgets', ['household_id' => $h, 'category_id' => 111, 'period_month' => '2026-09-01', 'limit_amount' => '100.00', 'created_at' => $ts]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => $acc, 'created_by' => $owner, 'type' => 'expense', 'amount' => '90.00', 'date' => '2026-09-05', 'description' => 'Padaria', 'status' => 'paid', 'category_id' => 111, 'created_at' => $ts]);
    // Dono: contas a vencer (3 dias) e atrasadas por push+e-mail, orçamento 80 %, entradas, atividade de outros acima de 100
    NS::save($owner, ['mode' => 'immediate', 'daily_limit' => '10', 'types' => ['due' => ['enabled' => '1', 'channel' => 'both', 'days' => ['3']], 'overdue' => ['enabled' => '1', 'channel' => 'email'], 'budget' => ['enabled' => '1', 'channel' => 'app', 'thresholds' => ['80']], 'income' => ['enabled' => '1', 'channel' => 'app'], 'member_activity' => ['mode' => 'above', 'min_amount' => '100,00', 'channel' => 'app']]]);
    Database::execute('UPDATE notification_settings SET settings = JSON_SET(settings, "$.channels.email", true, "$.channels.push", true) WHERE user_id = ?', [$owner]);
    $u = Database::selectOne('SELECT u.id, u.name, u.email, u.timezone, m.household_id, h.type AS household_type FROM users u JOIN household_members m ON m.user_id = u.id JOIN households h ON h.id = m.household_id WHERE u.id = ?', [$owner]);
    $created = NotificationScheduler::detect($u, NS::load($owner), $now, $now->modify('-1 hour'));
    $alerts = Database::select('SELECT type, title, channel, dedupe_key FROM alerts WHERE user_id = ? ORDER BY id', [$owner]);
    $types = array_column($alerts, 'type');
    assert_true(in_array('due', $types, true), 'Água vence em 3 dias');
    assert_true(in_array('overdue', $types, true), 'Luz atrasada');
    assert_true(in_array('budget', $types, true), 'padaria em 90 %');
    assert_true(in_array('income', $types, true), 'salário entrou');
    assert_true(in_array('member_activity', $types, true), 'membro lançou acima de 100');
    assert_same(count($alerts), $created);
    $due = array_values(array_filter($alerts, static fn(array $a): bool => $a['type'] === 'due'))[0];
    assert_same('both', $due['channel']);
    $again = NotificationScheduler::detect($u, NS::load($owner), $now->modify('+5 minutes'), $now);
    assert_same(0, $again, 'segunda passada não duplica (dedupe_key)');
    // Entrega: sem assinatura push, o e-mail vai para o outbox (MAIL_DRIVER=log) e sent_at é preenchido
    $out = NotificationScheduler::deliver($now);
    assert_true($out['email'] >= 2, 'due (both) + overdue (email)');
    assert_same(0, (int) Database::scalar("SELECT COUNT(*) FROM alerts WHERE user_id = ? AND sent_at IS NULL AND channel <> 'none'", [$owner]), 'tudo entregue');
    assert_true((int) Database::scalar("SELECT COUNT(*) FROM email_outbox WHERE user_id = ? AND kind = 'notification'", [$owner]) >= 2);
    // Horário silencioso: um aviso novo às 23h30 local fica adiado para as 7h
    NS::save($owner, ['quiet_enabled' => '1', 'quiet_start' => '22:00', 'quiet_end' => '07:00', 'quiet_days' => [1, 2, 3, 4, 5, 6, 7], 'types' => ['goal' => ['enabled' => '1', 'channel' => 'email']]]);
    Database::execute('UPDATE notification_settings SET settings = JSON_SET(settings, "$.channels.email", true) WHERE user_id = ?', [$owner]);
    AlertService::create($owner, 'goal', 'Meta batida', 'x', ['channel' => 'email', 'dedupe_key' => 'goal:test']);
    $night = new DateTimeImmutable('2026-09-19 02:30:00', new DateTimeZone('UTC'));   // 23:30 em SP
    $out = NotificationScheduler::deliver($night);
    assert_same(1, $out['adiados']);
    assert_same('2026-09-19 10:00:00', Database::scalar('SELECT scheduled_for FROM alerts WHERE user_id = ? AND dedupe_key = ?', [$owner, 'goal:test']), 'adiado para 07:00 SP = 10:00 UTC');
    // Modo "só resumo": avisos comuns ficam sem canal
    NS::save($owner, ['mode' => 'digest_only', 'types' => ['due' => ['enabled' => '1', 'channel' => 'both', 'days' => ['1']]]]);
    Database::execute('UPDATE notification_settings SET settings = JSON_SET(settings, "$.channels.email", true, "$.channels.push", true) WHERE user_id = ?', [$owner]);
    Database::insert('transactions', ['household_id' => $h, 'account_id' => $acc, 'created_by' => $owner, 'type' => 'expense', 'amount' => '55.00', 'date' => '2026-09-19', 'description' => 'Gás', 'status' => 'pending', 'created_at' => $ts]);
    NotificationScheduler::detect($u, NS::load($owner), $now, $now);
    assert_same('none', Database::scalar('SELECT channel FROM alerts WHERE user_id = ? AND dedupe_key LIKE ?', [$owner, 'due:tx:%:d1']));
    // Token de ação assinado
    $token = AlertService::actionToken($owner, 42);
    $parsed = AlertService::parseActionToken($token);
    assert_same(42, $parsed['transaction_id']); assert_same($owner, $parsed['user_id']);
    assert_null(AlertService::parseActionToken($token . 'x'));
}
