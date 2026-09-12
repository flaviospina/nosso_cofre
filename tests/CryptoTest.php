<?php
// tests/CryptoTest.php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Crypto;

function test_crypto_roundtrip_and_tamper_detection(): void
{
    $key = Crypto::generateKey();
    $cipher = Crypto::encrypt('observação secreta ção', $key);
    assert_true(str_starts_with($cipher, 'v1:'));
    assert_same('observação secreta ção', Crypto::decrypt($cipher, $key));
    // Dois ciframentos do mesmo texto nunca são iguais (nonce aleatório)
    assert_true($cipher !== Crypto::encrypt('observação secreta ção', $key));
    // Adulteração é detectada pela tag GCM
    $raw = base64_decode(substr($cipher, 3), true);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    assert_throws(RuntimeException::class, static fn() => Crypto::decrypt('v1:' . base64_encode($raw), $key));
    // Chave errada falha
    assert_throws(RuntimeException::class, static fn() => Crypto::decrypt($cipher, Crypto::generateKey()));
}

function test_crypto_uses_app_key_from_config(): void
{
    $old = Config::get('app.key');
    Config::set('app.key', Crypto::generateKey());
    $c = Crypto::encrypt('abc');
    assert_same('abc', Crypto::decrypt($c));
    assert_true(Crypto::isEncrypted($c));
    assert_false(Crypto::isEncrypted('abc'));
    assert_same(64, strlen(Crypto::hashToken('x')));
    assert_true(Crypto::verifySignature('dados', Crypto::sign('dados')));
    assert_false(Crypto::verifySignature('dados2', Crypto::sign('dados')));
    Config::set('app.key', $old);
}

function test_crypto_rejects_missing_or_short_key(): void
{
    assert_throws(RuntimeException::class, static fn() => Crypto::encrypt('x', ''));
    assert_throws(RuntimeException::class, static fn() => Crypto::encrypt('x', base64_encode('curta')));
}
