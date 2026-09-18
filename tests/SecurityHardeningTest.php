<?php
// tests/SecurityHardeningTest.php — fase 8: CSV sem fórmulas, visibilidade por consentimento, CAPTCHA de uso único, TOTP sem replay
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

use App\Core\Captcha;
use App\Core\Database;
use App\Services\ExportService;
use App\Services\PrivacyService;
use App\Services\ReportService;
use App\Services\TransactionPolicy;
use App\Services\TwoFactorService;

function test_csv_safe_neutralizes_formulas_but_keeps_numbers(): void
{
    assert_same("'=HYPERLINK(\"http://x\")", ExportService::csvSafe('=HYPERLINK("http://x")'));
    assert_same("'+cmd", ExportService::csvSafe('+cmd'));
    assert_same("'@SUM(A1)", ExportService::csvSafe('@SUM(A1)'));
    assert_same("'-2+3|cmd", ExportService::csvSafe('-2+3|cmd'));
    assert_same("'\tx", ExportService::csvSafe("\tx"));
    assert_same('-50,00', ExportService::csvSafe('-50,00'), 'número negativo fica número');
    assert_same('+10', ExportService::csvSafe('+10'));
    assert_same('−R$ 10,00', ExportService::csvSafe('-R$ 10,00'), 'valor monetário negativo usa U+2212');
    assert_same('Padaria', ExportService::csvSafe('Padaria'));
    assert_same('', ExportService::csvSafe(''));
    $csv = ReportService::csv(['headers' => ['A', 'B'], 'rows' => [['=1+1', 'x;y']]]);
    assert_true(str_contains($csv, "'=1+1;\"x;y\""), 'relatório em CSV passa pelo csvSafe');
}

function test_captcha_challenge_is_single_use(): void
{
    $_SESSION = [];
    $c = Captcha::challenge();
    assert_true(preg_match('/Quanto é (\d+) ([+-]) (\d+)\?/', $c['question'], $m) === 1);
    $answer = $m[2] === '+' ? (int) $m[1] + (int) $m[3] : (int) $m[1] - (int) $m[3];
    assert_false(Captcha::verify($c['token'], (string) ($answer + 1)), 'resposta errada');
    assert_true(Captcha::verify($c['token'], (string) $answer), 'resposta certa');
    assert_false(Captcha::verify($c['token'], (string) $answer), 'mesmo desafio não vale duas vezes');
    $_SESSION = [];
}

function test_visibility_follows_private_flag_and_share_consent(): void
{
    if (!privacy_db_available()) { return; }
    $f = privacy_fixture(true);
    $h = (int) $f['household']; $owner = (int) $f['owner']; $member = (int) $f['member'];
    $tx = ['created_by' => $member, 'is_private' => 0, 'household_id' => $h];
    assert_false(TransactionPolicy::isPrivateForMe($tx, $owner), 'comum de outro membro é visível');
    assert_true(TransactionPolicy::isPrivateForMe($tx + ['is_private' => 1], $owner) === false, 'chave repetida não muda');
    assert_true(TransactionPolicy::isPrivateForMe(['created_by' => $member, 'is_private' => 1, 'household_id' => $h], $owner), 'privado de outro fica oculto');
    assert_false(TransactionPolicy::isPrivateForMe(['created_by' => $member, 'is_private' => 1, 'household_id' => $h], $member), 'o próprio autor sempre vê');
    [$sql, $params] = TransactionPolicy::visibleSql('t', $owner, $h);
    $visible = static fn(string $sql, array $params): int => (int) Database::scalar("SELECT COUNT(*) FROM transactions t WHERE t.household_id = ? AND {$sql}", array_merge([$h], $params));
    assert_same(2, $visible($sql, $params), 'antes de revogar: os 2 lançamentos do membro aparecem para o titular');
    // Membro desliga "compartilhar com o lar"
    PrivacyService::setConsent($member, 'share_with_household', false);
    $ref = new ReflectionProperty(TransactionPolicy::class, 'nonSharing'); $ref->setValue(null, []); // limpa cache por requisição
    assert_same([$member], TransactionPolicy::nonSharingUserIds($h));
    assert_true(TransactionPolicy::isPrivateForMe($tx, $owner), 'sem compartilhar, tudo do membro vira privado para os outros');
    [$sql, $params] = TransactionPolicy::visibleSql('t', $owner, $h);
    assert_same(0, $visible($sql, $params), 'depois de revogar: nada do membro aparece');
    [$sql, $params] = TransactionPolicy::visibleSql('t', $member, $h);
    assert_same(2, $visible($sql, $params), 'o membro continua vendo os próprios');
    $masked = TransactionPolicy::mask(['created_by' => $member, 'is_private' => 0, 'household_id' => $h, 'description' => 'Segredo', 'notes' => 'n', 'tags' => ['a']], $owner);
    assert_same('Lançamento privado', $masked['description']); assert_null($masked['notes']); assert_true($masked['masked']);
    PrivacyService::setConsent($member, 'share_with_household', true);
    $ref->setValue(null, []);
    assert_same([], TransactionPolicy::nonSharingUserIds($h), 'consentimento de volta');
}

function test_totp_counter_update_is_atomic(): void
{
    if (!db_available('users')) { return; }
    $f = privacy_fixture(false);
    $id = (int) $f['owner'];
    $secret = \App\Core\Totp::generateSecret();
    Database::execute('UPDATE users SET totp_secret = ?, totp_enabled_at = ?, totp_last_counter = NULL WHERE id = ?', [$secret, gmdate('Y-m-d H:i:s'), $id]);
    $user = Database::selectOne('SELECT * FROM users WHERE id = ?', [$id]);
    $code = \App\Core\Totp::code($secret);
    assert_true(TwoFactorService::verifyCode($user, $code), 'primeiro uso vale');
    // Segunda verificação com o MESMO array (sem o contador atualizado), como em dois pedidos simultâneos
    assert_false(TwoFactorService::verifyCode($user, $code), 'replay é recusado pelo UPDATE condicional');
}
