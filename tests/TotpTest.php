<?php
// tests/TotpTest.php — vetores de teste da RFC 6238 (SHA-1) e comportamento da janela/reuso
declare(strict_types=1);

use App\Core\Totp;

function test_totp_rfc6238_vectors(): void
{
    // Segredo de referência "12345678901234567890" em Base32
    $secret = Totp::base32Encode('12345678901234567890');
    assert_same('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
    // Os códigos de 8 dígitos da RFC são 94287082, 07081804, 14050471...; os 6 últimos dígitos são o TOTP de 6 dígitos
    assert_same('287082', Totp::code($secret, 59));
    assert_same('081804', Totp::code($secret, 1111111109));
    assert_same('050471', Totp::code($secret, 1111111111));
    assert_same('005924', Totp::code($secret, 1234567890));
    assert_same('279037', Totp::code($secret, 2000000000));
}

function test_totp_verify_window_and_replay(): void
{
    $secret = Totp::generateSecret();
    assert_same(32, strlen($secret));
    $now = 1700000000;
    $code = Totp::code($secret, $now);
    $counter = Totp::verify($secret, $code, $now);
    assert_true($counter !== null, 'código atual deve valer');
    // Código do período anterior ainda vale (janela ±1)
    assert_true(Totp::verify($secret, Totp::code($secret, $now - 30), $now) !== null);
    // Dois períodos atrás não vale
    assert_null(Totp::verify($secret, Totp::code($secret, $now - 90), $now));
    // Reuso do mesmo código é bloqueado pelo contador
    assert_null(Totp::verify($secret, $code, $now, $counter));
    assert_null(Totp::verify($secret, '000000', $now));
    assert_null(Totp::verify($secret, 'abc', $now));
}

function test_totp_base32_roundtrip_and_uri(): void
{
    $raw = random_bytes(20);
    assert_same($raw, Totp::base32Decode(Totp::base32Encode($raw)));
    $uri = Totp::provisioningUri('ABCDEFGH', 'flavio@exemplo.test', 'Nosso Cofre');
    assert_contains('otpauth://totp/Nosso%20Cofre:flavio%40exemplo.test?', $uri);
    assert_contains('secret=ABCDEFGH', $uri);
    assert_contains('issuer=Nosso%20Cofre', $uri);
    $codes = Totp::generateRecoveryCodes(3);
    assert_same(3, count($codes));
    assert_true(preg_match('/^[a-z0-9]{5}-[a-z0-9]{5}$/', $codes[0]) === 1);
    assert_same('abcdeabcde', Totp::normalizeRecoveryCode('ABCDE-abcde'));
}
