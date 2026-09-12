<?php
// app/Core/Crypto.php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Criptografia simétrica (AES-256-GCM) para campos sensíveis em repouso
 * (segredo TOTP, observações, CPF opcional) e utilitários de tokens.
 *
 * Formato do texto cifrado: "v1:" + base64(nonce 12 bytes + tag 16 bytes + ciphertext).
 * A chave vem de APP_KEY (32 bytes em base64). Trocar a chave invalida tudo que foi cifrado.
 */
final class Crypto
{
    private const VERSION = 'v1';
    private const CIPHER = 'aes-256-gcm';

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    private static function key(?string $override = null): string
    {
        $encoded = $override ?? (string) Config::get('app.key', '');
        if ($encoded === '') {
            throw new RuntimeException('APP_KEY não configurado. Gere em /instalar e grave no .env.');
        }
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEY inválido: precisa ter 32 bytes em base64.');
        }
        return $key;
    }

    public static function encrypt(string $plain, ?string $keyOverride = null): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key($keyOverride), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Falha ao criptografar.');
        }
        return self::VERSION . ':' . base64_encode($nonce . $tag . $cipher);
    }

    public static function decrypt(string $payload, ?string $keyOverride = null): string
    {
        if (!str_starts_with($payload, self::VERSION . ':')) {
            throw new RuntimeException('Formato de dado criptografado desconhecido.');
        }
        $raw = base64_decode(substr($payload, strlen(self::VERSION) + 1), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Dado criptografado corrompido.');
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, self::CIPHER, self::key($keyOverride), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new RuntimeException('Falha ao descriptografar (chave errada ou dado adulterado).');
        }
        return $plain;
    }

    /** Descriptografa devolvendo null em vez de exceção (para exibição tolerante). */
    public static function tryDecrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        try {
            return self::decrypt($payload);
        } catch (\Throwable $e) {
            Logger::error('Falha ao descriptografar campo', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public static function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::VERSION . ':');
    }

    /** Token aleatório seguro em hexadecimal (2 caracteres por byte). */
    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Token aleatório em base64url (curto e seguro para URL). */
    public static function randomUrlToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Hash de tokens que ficam no banco (nunca guardar o token em claro). */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** HMAC para assinar valores que voltam do cliente (ex.: CAPTCHA próprio). */
    public static function sign(string $data): string
    {
        return hash_hmac('sha256', $data, self::key());
    }

    public static function verifySignature(string $data, string $signature): bool
    {
        return hash_equals(self::sign($data), $signature);
    }
}
