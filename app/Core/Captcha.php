<?php
// app/Core/Captcha.php
declare(strict_types=1);

namespace App\Core;

/**
 * Desafio próprio, sem serviço externo: uma conta simples ("Quanto é 7 + 5?") assinada com HMAC.
 * O token leva a resposta esperada (em hash), a validade e um nonce; o servidor não guarda estado.
 * É deliberadamente leve: o objetivo é atrapalhar robôs de força bruta após 3 falhas, não ser inquebrável.
 */
final class Captcha
{
    private const TTL = 600; // 10 minutos

    /** @return array{question:string,token:string} */
    public static function challenge(): array
    {
        $a = random_int(2, 9);
        $b = random_int(1, 9);
        $op = random_int(0, 1) === 0 ? '+' : '-';
        if ($op === '-' && $b > $a) {
            [$a, $b] = [$b, $a];
        }
        $answer = $op === '+' ? $a + $b : $a - $b;
        $nonce = Crypto::randomToken(8);
        $expires = time() + self::TTL;
        $payload = $nonce . '|' . $expires . '|' . hash('sha256', $nonce . ':' . $answer);
        $token = base64_encode($payload . '|' . Crypto::sign($payload));
        return [
            'question' => "Quanto é {$a} {$op} {$b}?",
            'token'    => $token,
        ];
    }

    public static function verify(?string $token, ?string $answer): bool
    {
        if ($token === null || $answer === null) {
            return false;
        }
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 4) {
            return false;
        }
        [$nonce, $expires, $answerHash, $signature] = $parts;
        $payload = $nonce . '|' . $expires . '|' . $answerHash;
        if (!Crypto::verifySignature($payload, $signature)) {
            return false;
        }
        if ((int) $expires < time()) {
            return false;
        }
        $given = trim($answer);
        if (!preg_match('/^-?\d{1,3}$/', $given)) {
            return false;
        }
        return hash_equals($answerHash, hash('sha256', $nonce . ':' . (int) $given));
    }
}
