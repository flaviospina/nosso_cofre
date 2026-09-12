<?php
// app/Core/Response.php
declare(strict_types=1);

namespace App\Core;

/**
 * Resposta HTTP. Todo envio passa por aqui, o que garante os cabeçalhos de segurança em todas as páginas.
 */
final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = []
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = (string) $value;
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Resposta JSON no padrão { ok, data, mensagem }. */
    public static function json(bool $ok, mixed $data = null, string $message = '', int $status = 200, array $extra = []): self
    {
        $payload = array_merge(['ok' => $ok, 'data' => $data, 'mensagem' => $message], $extra);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self($body === false ? '{"ok":false,"data":null,"mensagem":"Erro ao gerar JSON."}' : $body, $status, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** Download de arquivo que está fora da pasta pública (anexos, exportações, backups). */
    public static function file(string $path, string $downloadName, string $mime = 'application/octet-stream', bool $inline = false): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException(404, 'Arquivo não encontrado.');
        }
        $safeName = preg_replace('/[^\w\.\-]+/u', '_', $downloadName) ?: 'arquivo';
        $disposition = $inline ? 'inline' : 'attachment';
        $response = new self((string) file_get_contents($path), 200, [
            'Content-Type'        => $mime,
            'Content-Length'      => (string) filesize($path),
            'Content-Disposition' => sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, $safeName, rawurlencode($downloadName)),
            'Cache-Control'       => 'private, no-store',
        ]);
        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->securityHeaders() as $name => $value) {
                header($name . ': ' . $value);
            }
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }

    /** @return array<string,string> */
    private function securityHeaders(): array
    {
        $nonce = App::nonce();
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net",
            "style-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net",
            "font-src 'self' https://cdn.jsdelivr.net data:",
            "img-src 'self' data: blob:",
            "media-src 'self'",
            "connect-src 'self'",
            "worker-src 'self'",
            "manifest-src 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            'upgrade-insecure-requests',
        ]);
        $headers = [
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
            'Permissions-Policy'      => 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()',
            'Cache-Control'           => 'no-store, no-cache, must-revalidate',
            'Pragma'                  => 'no-cache',
        ];
        if (str_starts_with((string) Config::get('app.url', ''), 'https://')) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        return $headers;
    }
}
