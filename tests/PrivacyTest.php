<?php
// tests/PrivacyTest.php — serviços de privacidade (integração: precisa do banco do .env; pulados se indisponível)
declare(strict_types=1);

use App\Core\Database;
use App\Services\ExportService;
use App\Services\PrivacyService;
use App\Services\RetentionService;

function privacy_db_available(): bool
{
    return Database::isConfigured() && Database::ping() && Database::scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'deletion_requests'") > 0;
}

/** Cria usuário + lar (individual ou com um segundo membro) para os testes; devolve ids. */
function privacy_fixture(bool $withMember = false): array
{
    $suffix = bin2hex(random_bytes(4));
    $now = gmdate('Y-m-d H:i:s');
    $owner = Database::insert('users', ['name' => 'Tit ' . $suffix, 'email' => "tit-{$suffix}@teste.invalid", 'email_verified_at' => $now, 'password_hash' => 'x', 'color' => '#000000', 'adult_confirmed_at' => $now, 'status' => 'active', 'created_at' => $now]);
    $household = Database::insert('households', ['name' => 'Lar ' . $suffix, 'type' => $withMember ? 'family' : 'individual', 'currency' => 'BRL', 'fiscal_month_start_day' => 1, 'owner_user_id' => $owner, 'status' => 'active', 'created_at' => $now]);
    Database::insert('household_members', ['household_id' => $household, 'user_id' => $owner, 'role' => 'owner', 'joined_at' => $now, 'created_at' => $now]);
    $member = null;
    if ($withMember) {
        $member = Database::insert('users', ['name' => 'Mem ' . $suffix, 'email' => "mem-{$suffix}@teste.invalid", 'email_verified_at' => $now, 'password_hash' => 'x', 'color' => '#111111', 'adult_confirmed_at' => $now, 'status' => 'active', 'created_at' => $now]);
        Database::insert('household_members', ['household_id' => $household, 'user_id' => $member, 'role' => 'member', 'joined_at' => $now, 'created_at' => $now]);
    }
    $account = Database::insert('accounts', ['household_id' => $household, 'name' => 'Conta', 'type' => 'checking', 'owner_user_id' => $member ?? $owner, 'created_at' => $now]);
    $joint = Database::insert('accounts', ['household_id' => $household, 'name' => 'Conjunta', 'type' => 'cash', 'owner_user_id' => null, 'created_at' => $now]);
    $by = $member ?? $owner;
    Database::insert('transactions', ['household_id' => $household, 'account_id' => $account, 'category_id' => null, 'responsible_user_id' => $by, 'created_by' => $by, 'type' => 'expense', 'amount' => '10.00', 'date' => '2026-09-01', 'description' => 'Padaria', 'status' => 'paid', 'created_at' => $now]);
    Database::insert('transactions', ['household_id' => $household, 'account_id' => $joint, 'category_id' => null, 'responsible_user_id' => $by, 'created_by' => $by, 'type' => 'expense', 'amount' => '20.00', 'date' => '2026-09-02', 'description' => 'Mercado', 'status' => 'paid', 'created_at' => $now]);
    Database::insert('consents', ['user_id' => $by, 'kind' => 'terms', 'granted' => 1, 'document_version' => '1.0', 'ip' => '10.0.0.1', 'user_agent' => 'ua', 'created_at' => $now]);
    return ['owner' => $owner, 'member' => $member, 'household' => $household, 'account' => $account, 'joint' => $joint, 'email' => "tit-{$suffix}@teste.invalid"];
}

function test_privacy_consents_toggle_and_revoke_all(): void
{
    if (!privacy_db_available()) { return; }
    $f = privacy_fixture();
    $u = $f['owner'];
    $state = PrivacyService::consentState($u);
    assert_true($state['transactional_email']); assert_false($state['push']);
    assert_true(PrivacyService::setConsent($u, 'push', true));
    assert_true(PrivacyService::consentState($u)['push']);
    assert_false(PrivacyService::setConsent($u, 'transactional_email', false), 'e-mail necessário não pode ser revogado');
    PrivacyService::revokeAllNotifications($u);
    $state = PrivacyService::consentState($u);
    assert_false($state['push']); assert_false($state['digest_email']);
    $rows = Database::select('SELECT kind, granted FROM consents WHERE user_id = ? AND kind = ? ORDER BY id', [$u, 'push']);
    assert_same(2, count($rows), 'cada mudança gera uma linha de consentimento');
}

function test_privacy_anonymize_keeps_transactions_in_household(): void
{
    if (!privacy_db_available()) { return; }
    $f = privacy_fixture(true);
    PrivacyService::anonymizeUser((int) $f['member']);
    $u = Database::selectOne('SELECT * FROM users WHERE id = ?', [$f['member']]);
    assert_same('Membro removido', $u['name']);
    assert_same('anonymized', $u['status']);
    assert_true(str_ends_with((string) $u['email'], '@anonimizado.invalid'));
    assert_same('', (string) $u['password_hash']);
    assert_same(2, (int) Database::scalar('SELECT COUNT(*) FROM transactions WHERE household_id = ? AND responsible_user_id = ?', [$f['household'], $f['member']]), 'lançamentos seguem no lar');
    assert_null(Database::scalar('SELECT ip FROM consents WHERE user_id = ? LIMIT 1', [$f['member']]), 'IP do consentimento apagado');
    assert_true(Database::scalar('SELECT left_at FROM household_members WHERE user_id = ?', [$f['member']]) !== null, 'saiu do lar');
}

function test_privacy_owner_with_members_cannot_delete_account(): void
{
    if (!privacy_db_available()) { return; }
    $f = privacy_fixture(true);
    $error = PrivacyService::requestAccountDeletion((int) $f['owner'], '127.0.0.1');
    assert_true(is_string($error) && str_contains($error, 'Transfira'), (string) $error);
    // Membro comum pode
    assert_null(PrivacyService::requestAccountDeletion((int) $f['member'], '127.0.0.1'));
    assert_same('pending_deletion', Database::scalar('SELECT status FROM users WHERE id = ?', [$f['member']]));
    assert_true(PrivacyService::cancelAccountDeletion((int) $f['member']));
    assert_same('active', Database::scalar('SELECT status FROM users WHERE id = ?', [$f['member']]));
}

function test_privacy_deletion_executes_after_grace_and_removes_sole_household(): void
{
    if (!privacy_db_available()) { return; }
    $f = privacy_fixture();
    assert_null(PrivacyService::requestAccountDeletion((int) $f['owner'], '127.0.0.1'));
    assert_same(0, PrivacyService::executeDueDeletions(), 'dentro da carência nada acontece');
    Database::execute("UPDATE deletion_requests SET scheduled_for = ? WHERE user_id = ?", [gmdate('Y-m-d H:i:s', time() - 60), $f['owner']]);
    assert_same(1, PrivacyService::executeDueDeletions());
    assert_null(Database::selectOne('SELECT id FROM households WHERE id = ?', [$f['household']]), 'lar individual apagado');
    assert_same(0, (int) Database::scalar('SELECT COUNT(*) FROM transactions WHERE household_id = ?', [$f['household']]));
    assert_same(0, (int) Database::scalar('SELECT COUNT(*) FROM login_attempts WHERE email = ?', [$f['email']]));
    $u = Database::selectOne('SELECT status, deleted_at FROM users WHERE id = ?', [$f['owner']]);
    assert_same('anonymized', $u['status']); assert_true($u['deleted_at'] !== null);
}

function test_privacy_household_deletion_request_and_cancel(): void
{
    if (!privacy_db_available()) { return; }
    $f = privacy_fixture(true);
    assert_null(PrivacyService::requestHouseholdDeletion((int) $f['household'], (int) $f['owner'], '127.0.0.1'));
    assert_same('pending_deletion', Database::scalar('SELECT status FROM households WHERE id = ?', [$f['household']]));
    assert_true(PrivacyService::pendingHouseholdDeletion((int) $f['household']) !== null);
    assert_true(PrivacyService::cancelHouseholdDeletion((int) $f['household'], (int) $f['owner']));
    assert_same('active', Database::scalar('SELECT status FROM households WHERE id = ?', [$f['household']]));
}

function test_privacy_leave_household_taking_data(): void
{
    if (!privacy_db_available()) { return; }
    $f = privacy_fixture(true);
    $newId = PrivacyService::leaveHousehold((int) $f['member'], (int) $f['household'], true);
    assert_true($newId !== null && $newId > 0, 'lar individual criado');
    assert_same($newId, (int) Database::scalar('SELECT household_id FROM accounts WHERE id = ?', [$f['account']]), 'conta própria levada');
    assert_same((int) $f['household'], (int) Database::scalar('SELECT household_id FROM accounts WHERE id = ?', [$f['joint']]), 'conta conjunta fica');
    assert_same(1, (int) Database::scalar('SELECT COUNT(*) FROM transactions WHERE household_id = ?', [$newId]), 'lançamento da conta própria levado');
    $left = Database::selectOne('SELECT responsible_user_id FROM transactions WHERE household_id = ? AND account_id = ?', [$f['household'], $f['joint']]);
    assert_null($left['responsible_user_id'], 'lançamento em conta conjunta fica sem responsável');
    assert_true(Database::scalar('SELECT left_at FROM household_members WHERE user_id = ? AND household_id = ?', [$f['member'], $f['household']]) !== null);
    // Sem levar dados
    $g = privacy_fixture(true);
    assert_same(0, PrivacyService::leaveHousehold((int) $g['member'], (int) $g['household'], false));
    assert_same(2, (int) Database::scalar('SELECT COUNT(*) FROM transactions WHERE household_id = ? AND responsible_user_id IS NULL', [$g['household']]));
}

function test_privacy_export_zip_contents(): void
{
    if (!privacy_db_available() || !class_exists('ZipArchive')) { return; }
    $f = privacy_fixture(true);
    $export = ExportService::create((int) $f['member']);
    assert_true($export['size'] > 0);
    $row = ExportService::findValid($export['token'], (int) $f['member']);
    assert_true($row !== null, 'link válido para o dono');
    assert_null(ExportService::findValid($export['token'], (int) $f['owner']), 'outro usuário não acessa');
    $zip = new ZipArchive();
    assert_true($zip->open($row['absolute_path']) === true);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
    assert_true(in_array('meus-dados.json', $names, true) && in_array('lancamentos.csv', $names, true) && in_array('LEIA-ME.txt', $names, true), implode(',', $names));
    $json = json_decode((string) $zip->getFromName('meus-dados.json'), true);
    assert_same(2, count($json['lancamentos']));
    assert_contains('Padaria', (string) $zip->getFromName('lancamentos.csv'));
    $zip->close();
    Database::execute('UPDATE data_exports SET expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 10), $export['id']]);
    assert_true(ExportService::purgeExpired() >= 1);
    assert_false(is_file($row['absolute_path']), 'arquivo apagado após expirar');
}

function test_privacy_retention_purges_old_records(): void
{
    if (!privacy_db_available()) { return; }
    Database::insert('login_attempts', ['ip' => '1.2.3.4', 'succeeded' => 0, 'kind' => 'login', 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-13 months'))]);
    $f = privacy_fixture();
    Database::execute('UPDATE transactions SET deleted_at = ? WHERE household_id = ?', [gmdate('Y-m-d H:i:s', strtotime('-40 days')), $f['household']]);
    $result = RetentionService::run();
    assert_true($result['tentativas_de_login'] >= 1);
    assert_true($result['lixeira'] >= 2);
    assert_same(0, (int) Database::scalar('SELECT COUNT(*) FROM transactions WHERE household_id = ?', [$f['household']]));
}

function test_export_csv_format(): void
{
    $csv = ExportService::csv([['a' => 'x;y', 'b' => ['k' => 1]], ['a' => 'z', 'b' => null]]);
    assert_true(str_starts_with($csv, "\xEF\xBB\xBF"), 'BOM para Excel');
    assert_contains("a;b\n\"x;y\";\"{\"\"k\"\":1}\"\nz;", $csv);
}
