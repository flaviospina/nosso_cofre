<?php
// tests/RateLimiterTest.php — CAPTCHA após 3 falhas, bloqueio progressivo e limpeza ao acertar (integração)
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

use App\Core\Database;
use App\Core\RateLimiter;

function test_rate_limiter_captcha_and_progressive_block(): void
{
    if (!db_available('login_attempts')) { return; }
    $ip = '203.0.113.' . random_int(1, 254);
    $email = 'rl-' . bin2hex(random_bytes(3)) . '@teste.invalid';
    $st = RateLimiter::status('login', $ip, $email);
    assert_false($st['captcha']); assert_same(0, $st['blocked_seconds']);
    for ($i = 0; $i < 3; $i++) {
        RateLimiter::record('login', $ip, $email, false, 'ua');
    }
    $st = RateLimiter::status('login', $ip, $email);
    assert_true($st['captcha'], 'CAPTCHA a partir de 3 falhas'); assert_same(0, $st['blocked_seconds']);
    RateLimiter::record('login', $ip, $email, false, 'ua');
    RateLimiter::record('login', $ip, $email, false, 'ua');
    $st = RateLimiter::status('login', $ip, $email);
    assert_true($st['blocked_seconds'] > 0 && $st['blocked_seconds'] <= 60, '5 falhas → 1 minuto');
    // Mesmo e-mail de outro IP também conta (bloqueio por conta)
    $st2 = RateLimiter::status('login', '198.51.100.7', $email);
    assert_true($st2['captcha'], 'falhas da conta valem para qualquer IP');
    // Sucesso limpa as falhas da conta
    RateLimiter::clear('login', $email);
    Database::execute('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
    $st = RateLimiter::status('login', $ip, $email);
    assert_false($st['captcha']); assert_same(0, $st['blocked_seconds']);
    // Tipos independentes: falhas de login não bloqueiam cadastro
    for ($i = 0; $i < 6; $i++) { RateLimiter::record('login', $ip, null, false, 'ua'); }
    assert_same(0, RateLimiter::status('register', $ip, null)['blocked_seconds']);
    assert_true(RateLimiter::status('login', $ip, null)['blocked_seconds'] > 0);
    Database::execute('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
}
