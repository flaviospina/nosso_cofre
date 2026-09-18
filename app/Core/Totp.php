<?php
// app/Core/Totp.php
declare(strict_types=1);

namespace App\Core;

/**
 * TOTP (RFC 6238) e HOTP (RFC 4226) em código próprio: SHA-1, 6 dígitos, período de 30 s,
 * compatível com Google Authenticator, Authy, Microsoft Authenticator, 1Password etc.
 * Implementado aqui em vez de vendorizar uma biblioteca: são ~100 linhas testáveis e sem dependências.
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    /** Janela de tolerância: aceita o código anterior e o próximo (relógio do celular fora de sincronia). */
    public const WINDOW = 1;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Segredo novo em Base32 (20 bytes = 160 bits, o padrão do Authenticator). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secretBase32, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), self::PERIOD);
        return self::hotp(self::base32Decode($secretBase32), $counter);
    }

    /**
     * Verifica o código dentro da janela. Devolve o "contador" aceito (para bloquear reuso do mesmo código)
     * ou null quando inválido.
     */
    public static function verify(string $secretBase32, string $code, ?int $timestamp = null, ?int $lastUsedCounter = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $key = self::base32Decode($secretBase32);
        $current = intdiv($timestamp ?? time(), self::PERIOD);
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $counter = $current + $offset;
            if ($lastUsedCounter !== null && $counter <= $lastUsedCounter) {
                continue; // código já utilizado (ou anterior a um já utilizado): não aceitar de novo
            }
            if (hash_equals(self::hotp($key, $counter), $code)) {
                return $counter;
            }
        }
        return null;
    }

    public static function hotp(string $key, int $counter): string
    {
        $binaryCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** URI para o QR code (otpauth://). */
    public static function provisioningUri(string $secretBase32, string $accountLabel, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountLabel);
        $query = http_build_query([
            'secret'    => $secretBase32,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /** Segredo em grupos de 4 para digitação manual. */
    public static function formatSecret(string $secretBase32): string
    {
        return trim(chunk_split($secretBase32, 4, ' '));
    }

    /** Códigos de recuperação de uso único (10 códigos de 10 caracteres, formato xxxxx-xxxxx). */
    /** @return list<string> */
    public static function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789'; // sem caracteres ambíguos
        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
        }
        return $codes;
    }

    public static function normalizeRecoveryCode(string $code): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $code) ?? '');
    }

    public static function base32Encode(string $data): string
    {
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($binary, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::BASE32_ALPHABET[bindec($chunk)];
        }
        return $encoded;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $encoded) ?? '');
        $binary = '';
        foreach (str_split($encoded) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                throw new \InvalidArgumentException('Segredo Base32 inválido.');
            }
            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $data = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $data .= chr((int) bindec($byte));
            }
        }
        return $data;
    }
}
