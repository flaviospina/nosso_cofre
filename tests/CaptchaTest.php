<?php
// tests/CaptchaTest.php
declare(strict_types=1);

use App\Core\Captcha;
use App\Core\Config;
use App\Core\Crypto;

function test_captcha_challenge_verifies_correct_answer_only(): void
{
    $old = Config::get('app.key');
    Config::set('app.key', Crypto::generateKey());
    $c = Captcha::challenge();
    assert_true(preg_match('/^Quanto é (\d+) ([+-]) (\d+)\?$/', $c['question'], $m) === 1, $c['question']);
    $answer = $m[2] === '+' ? (int) $m[1] + (int) $m[3] : (int) $m[1] - (int) $m[3];
    assert_true(Captcha::verify($c['token'], (string) $answer));
    assert_false(Captcha::verify($c['token'], (string) ($answer + 1)));
    assert_false(Captcha::verify($c['token'], 'x'));
    assert_false(Captcha::verify('lixo', '1'));
    assert_false(Captcha::verify(null, null));
    // Token assinado com outra chave é recusado
    Config::set('app.key', Crypto::generateKey());
    assert_false(Captcha::verify($c['token'], (string) $answer));
    Config::set('app.key', $old);
}
