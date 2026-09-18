<?php
// app/Core/Mailer.php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Envio de e-mail por SMTP com cliente próprio (sem PHPMailer): SSL implícito (465) ou STARTTLS (587),
 * AUTH LOGIN/PLAIN, mensagens multipart (texto + HTML) em UTF-8.
 *
 * Toda mensagem é registrada em email_outbox: se o SMTP falhar, fica "pending" e o cron tenta de novo.
 * MAIL_DRIVER=log (desenvolvimento) não conecta em nada: grava a mensagem em storage/logs/mail-*.log.
 */
final class Mailer
{
    /**
     * Envia agora (com registro no outbox). Devolve o id do outbox.
     * @param array<string,mixed> $data variáveis do template
     */
    public static function send(string $toEmail, ?string $toName, string $subject, string $template, array $data = [], string $kind = 'transactional', ?int $userId = null): int
    {
        $html = View::renderFile('emails/' . $template, $data + ['subject' => $subject], );
        $text = self::htmlToText($html);
        $html = View::renderFile('emails/layout', ['subject' => $subject, 'content' => $html]);

        $id = Database::insert('email_outbox', [
            'user_id'    => $userId,
            'to_email'   => $toEmail,
            'to_name'    => $toName,
            'subject'    => $subject,
            'body_html'  => $html,
            'body_text'  => $text,
            'kind'       => $kind,
            'status'     => 'pending',
            'attempts'   => 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        self::attempt($id);
        return $id;
    }

    /** Tenta entregar uma mensagem do outbox (usado no envio imediato e pelo cron). */
    public static function attempt(int $outboxId): bool
    {
        $row = Database::selectOne('SELECT * FROM email_outbox WHERE id = ?', [$outboxId]);
        if ($row === null || $row['status'] === 'sent') {
            return $row !== null;
        }
        try {
            self::deliver((string) $row['to_email'], $row['to_name'] !== null ? (string) $row['to_name'] : null, (string) $row['subject'], (string) $row['body_html'], (string) $row['body_text']);
            // Enviado: o corpo (que pode conter links com token) não fica guardado
            Database::execute("UPDATE email_outbox SET status = ?, attempts = attempts + 1, sent_at = ?, last_error = NULL, body_html = '', body_text = NULL WHERE id = ?", ['sent', gmdate('Y-m-d H:i:s'), $outboxId]);
            return true;
        } catch (\Throwable $e) {
            $attempts = (int) $row['attempts'] + 1;
            $status = $attempts >= 5 ? 'failed' : 'pending';
            Database::execute('UPDATE email_outbox SET status = ?, attempts = ?, last_error = ?, scheduled_for = ? WHERE id = ?', [
                $status,
                $attempts,
                mb_substr($e->getMessage(), 0, 255),
                gmdate('Y-m-d H:i:s', time() + min(3600, 300 * $attempts)),
                $outboxId,
            ]);
            Logger::error('Falha ao enviar e-mail', ['outbox_id' => $outboxId, 'to' => $row['to_email'], 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Reprocessa pendências (cron). Devolve quantas foram enviadas. */
    public static function flushPending(int $limit = 20): int
    {
        $rows = Database::select(
            "SELECT id FROM email_outbox WHERE status = 'pending' AND (scheduled_for IS NULL OR scheduled_for <= ?) ORDER BY id ASC LIMIT " . (int) $limit,
            [gmdate('Y-m-d H:i:s')]
        );
        $sent = 0;
        foreach ($rows as $row) {
            if (self::attempt((int) $row['id'])) {
                $sent++;
            }
        }
        return $sent;
    }

    private static function deliver(string $toEmail, ?string $toName, string $subject, string $html, string $text): void
    {
        $driver = (string) Config::get('mail.driver', 'smtp');
        $fromEmail = (string) Config::get('mail.from_address');
        $fromName = (string) Config::get('mail.from_name');
        $message = self::buildMessage($fromEmail, $fromName, $toEmail, $toName, $subject, $html, $text);

        if ($driver === 'log') {
            $file = (string) Config::get('paths.logs') . '/mail-' . gmdate('Y-m-d') . '.log';
            file_put_contents($file, "=== " . gmdate('c') . " para {$toEmail}: {$subject}\n{$text}\n\n", FILE_APPEND | LOCK_EX);
            return;
        }
        if ($driver === 'mail') {
            $headers = self::headerLines($fromEmail, $fromName, $toName, $toEmail, $subject, $message['boundary']);
            unset($headers['To'], $headers['Subject']);
            $ok = mail(self::addressHeader($toEmail, $toName), self::encodeHeader($subject), $message['body'], implode("\r\n", array_map(static fn($k, $v) => "{$k}: {$v}", array_keys($headers), $headers)));
            if (!$ok) {
                throw new RuntimeException('A função mail() do PHP recusou a mensagem.');
            }
            return;
        }
        self::smtpSend($fromEmail, $toEmail, $message['raw']);
    }

    /** @return array{raw:string,body:string,boundary:string} */
    private static function buildMessage(string $fromEmail, string $fromName, string $toEmail, ?string $toName, string $subject, string $html, string $text): array
    {
        $boundary = 'nc_' . bin2hex(random_bytes(12));
        $headers = self::headerLines($fromEmail, $fromName, $toName, $toEmail, $subject, $boundary);
        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($text) . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($html) . "\r\n\r\n"
            . "--{$boundary}--\r\n";
        $raw = '';
        foreach ($headers as $name => $value) {
            $raw .= "{$name}: {$value}\r\n";
        }
        $raw .= "\r\n" . $body;
        return ['raw' => $raw, 'body' => $body, 'boundary' => $boundary];
    }

    /** @return array<string,string> */
    private static function headerLines(string $fromEmail, string $fromName, ?string $toName, string $toEmail, string $subject, string $boundary): array
    {
        $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
        return [
            'Date'         => gmdate('D, d M Y H:i:s') . ' +0000',
            'From'         => self::addressHeader($fromEmail, $fromName),
            'To'           => self::addressHeader($toEmail, $toName),
            'Subject'      => self::encodeHeader($subject),
            'Message-ID'   => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer'     => 'NossoCofre',
            'Auto-Submitted' => 'auto-generated',
        ];
    }

    private static function addressHeader(string $email, ?string $name): string
    {
        $email = trim($email);
        if ($name === null || trim($name) === '') {
            return $email;
        }
        return self::encodeHeader(trim($name)) . ' <' . $email . '>';
    }

    private static function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private static function smtpSend(string $fromEmail, string $toEmail, string $raw): void
    {
        $host = (string) Config::get('mail.host');
        $port = (int) Config::get('mail.port', 465);
        $encryption = strtolower((string) Config::get('mail.encryption', 'ssl'));
        $user = (string) Config::get('mail.user');
        $pass = (string) Config::get('mail.pass');
        if ($host === '') {
            throw new RuntimeException('MAIL_HOST não configurado.');
        }

        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($target, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("Não conectou ao SMTP {$host}:{$port} ({$errstr}).");
        }
        stream_set_timeout($socket, 20);

        $read = static function () use ($socket): string {
            $response = '';
            while (($line = fgets($socket, 1024)) !== false) {
                $response .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            return $response;
        };
        $expect = static function (string $response, array $codes, string $step): void {
            $code = (int) substr($response, 0, 3);
            if (!in_array($code, $codes, true)) {
                throw new RuntimeException("SMTP {$step}: " . trim($response));
            }
        };
        $command = static function (string $line) use ($socket, $read): string {
            fwrite($socket, $line . "\r\n");
            return $read();
        };

        try {
            $expect($read(), [220], 'conexão');
            $ehloHost = (string) (parse_url((string) Config::get('app.url'), PHP_URL_HOST) ?: 'localhost');
            $expect($command('EHLO ' . $ehloHost), [250], 'EHLO');
            if ($encryption === 'tls') {
                $expect($command('STARTTLS'), [220], 'STARTTLS');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Falha ao iniciar TLS.');
                }
                $expect($command('EHLO ' . $ehloHost), [250], 'EHLO pós-TLS');
            }
            if ($user !== '') {
                $auth = $command('AUTH LOGIN');
                if ((int) substr($auth, 0, 3) === 334) {
                    $expect($command(base64_encode($user)), [334], 'usuário');
                    $expect($command(base64_encode($pass)), [235], 'senha (verifique MAIL_USER/MAIL_PASS)');
                } else {
                    $expect($command('AUTH PLAIN ' . base64_encode("\0{$user}\0{$pass}")), [235], 'autenticação');
                }
            }
            $expect($command('MAIL FROM:<' . $fromEmail . '>'), [250], 'MAIL FROM');
            $expect($command('RCPT TO:<' . $toEmail . '>'), [250, 251], 'RCPT TO');
            $expect($command('DATA'), [354], 'DATA');
            // Dot-stuffing: linhas que começam com "." recebem outro "."
            $data = preg_replace('/^\./m', '..', $raw) ?? $raw;
            fwrite($socket, rtrim($data, "\r\n") . "\r\n.\r\n");
            $expect($read(), [250], 'envio');
            $command('QUIT');
        } finally {
            fclose($socket);
        }
    }

    /** Versão texto simples a partir do HTML do template (para o multipart e para o log). */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html) ?? $html;
        $text = preg_replace('#<(br|/p|/h[1-6]|/li|/tr)[^>]*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<li[^>]*>#i', '- ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim(preg_replace('/^[ \t]+/m', '', $text) ?? $text);
    }
}
