<?php
// app/Core/WebPush.php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Web Push sem biblioteca externa: VAPID (RFC 8292, JWT ES256) + criptografia do payload (RFC 8291, aes128gcm).
 * Usa só a extensão OpenSSL (curva P-256, ECDH, HKDF, AES-128-GCM) e curl. Sem gmp/bcmath.
 */
final class WebPush
{
    /** Gera um par de chaves VAPID (P-256) em base64url. @return array{public:string,private:string} */
    public static function generateVapidKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new RuntimeException('OpenSSL não conseguiu gerar a chave EC P-256.');
        }
        $d = openssl_pkey_get_details($key);
        return [
            'public'  => self::b64url("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)),
            'private' => self::b64url(str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    /**
     * Envia um push. @param array{endpoint:string,p256dh:string,auth:string} $subscription
     * @return array{ok:bool,status:int,gone:bool,error:string}
     */
    public static function send(array $subscription, string $payload, string $vapidPublic, string $vapidPrivate, string $subject, int $ttl = 86400, string $urgency = 'normal'): array
    {
        $endpoint = (string) $subscription['endpoint'];
        $parts = parse_url($endpoint);
        if (!isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https') {
            return ['ok' => false, 'status' => 0, 'gone' => true, 'error' => 'Endpoint inválido.'];
        }
        $audience = $parts['scheme'] . '://' . $parts['host'];
        $jwt = self::vapidJwt($audience, $subject, $vapidPrivate, $vapidPublic);
        $body = self::encrypt($payload, (string) $subscription['p256dh'], (string) $subscription['auth']);
        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Content-Length: ' . strlen($body),
            'TTL: ' . $ttl,
            'Urgency: ' . $urgency,
            'Authorization: vapid t=' . $jwt . ', k=' . $vapidPublic,
        ];
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $response === false ? (string) curl_error($ch) : '';
        curl_close($ch);
        $ok = $status >= 200 && $status < 300;
        return ['ok' => $ok, 'status' => $status, 'gone' => in_array($status, [404, 410], true), 'error' => $ok ? '' : ($error !== '' ? $error : 'HTTP ' . $status . ' ' . mb_substr(trim((string) $response), 0, 120))];
    }

    /** JWT ES256 do VAPID (válido por 12 h). */
    public static function vapidJwt(string $audience, string $subject, string $privateB64, string $publicB64): string
    {
        $header = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64url(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
        $pem = self::ecPrivatePem(self::b64decode($privateB64), self::b64decode($publicB64));
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new RuntimeException('Chave privada VAPID inválida.');
        }
        $der = '';
        if (!openssl_sign($header . '.' . $claims, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Falha ao assinar o JWT VAPID.');
        }
        return $header . '.' . $claims . '.' . self::b64url(self::derToRaw($der));
    }

    /** Criptografa o payload no formato aes128gcm (RFC 8291 + RFC 8188) para a assinatura do navegador. */
    public static function encrypt(string $payload, string $p256dhB64, string $authB64, ?string $localPem = null, ?string $salt = null): string
    {
        $receiverPublic = self::b64decode($p256dhB64);   // 65 bytes, ponto não comprimido
        $authSecret = self::b64decode($authB64);           // 16 bytes
        if (strlen($receiverPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new RuntimeException('Assinatura push com chaves inválidas.');
        }
        $local = $localPem !== null ? openssl_pkey_get_private($localPem) : openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($local === false) {
            throw new RuntimeException('Falha ao gerar a chave efêmera.');
        }
        $details = openssl_pkey_get_details($local);
        $localPublic = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $receiverKey = openssl_pkey_get_public(self::ecPublicPem($receiverPublic));
        if ($receiverKey === false) {
            throw new RuntimeException('Chave pública do navegador inválida.');
        }
        $shared = openssl_pkey_derive($receiverKey, $local, 32);
        if ($shared === false) {
            throw new RuntimeException('Falha no ECDH.');
        }
        $salt = $salt ?? random_bytes(16);
        // RFC 8291: IKM = HKDF(auth, ecdh, "WebPush: info\0" || ua_public || as_public, 32)
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $receiverPublic . $localPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Falha ao cifrar o payload.');
        }
        // Cabeçalho RFC 8188: salt(16) | rs(4) | idlen(1) | keyid(65)
        $rs = strlen($payload) + 1 + 16 + 16;
        return $salt . pack('N', max($rs, 4096)) . chr(65) . $localPublic . $cipher . $tag;
    }

    /** Descriptografa um payload aes128gcm (usado nos testes para provar a cifra; o navegador faz isso na prática). */
    public static function decrypt(string $body, string $receiverPrivatePem, string $authB64): string
    {
        $salt = substr($body, 0, 16);
        $idLen = ord($body[20]);
        $senderPublic = substr($body, 21, $idLen);
        $cipherAndTag = substr($body, 21 + $idLen);
        $priv = openssl_pkey_get_private($receiverPrivatePem);
        $details = openssl_pkey_get_details($priv);
        $receiverPublic = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $shared = openssl_pkey_derive(openssl_pkey_get_public(self::ecPublicPem($senderPublic)), $priv, 32);
        $ikm = hash_hkdf('sha256', (string) $shared, 32, "WebPush: info\0" . $receiverPublic . $senderPublic, self::b64decode($authB64));
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($cipherAndTag, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipherAndTag, -16));
        if ($plain === false) {
            throw new RuntimeException('Payload inválido.');
        }
        return rtrim($plain, "\x02");
    }

    // --- codificação ---

    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64decode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        return (string) base64_decode(str_pad($s, strlen($s) + (4 - strlen($s) % 4) % 4, '='), true);
    }

    /** PEM de chave pública EC P-256 a partir do ponto não comprimido (65 bytes). */
    public static function ecPublicPem(string $point): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** PEM de chave privada EC P-256 (SEC1) a partir dos 32 bytes do escalar + ponto público. */
    public static function ecPrivatePem(string $d, string $point): string
    {
        $der = hex2bin('30770201010420') . str_pad($d, 32, "\0", STR_PAD_LEFT) . hex2bin('a00a06082a8648ce3d030107a144034200') . $point;
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    /** Assinatura DER (SEQUENCE de dois INTEGER) → r||s de 64 bytes (JOSE). */
    private static function derToRaw(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $pos++; // 0x02
            $len = ord($der[$pos++]);
            $int = substr($der, $pos, $len);
            $pos += $len;
            $int = ltrim($int, "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }
}
