<?php
// app/Core/Request.php
declare(strict_types=1);

namespace App\Core;

/**
 * Representa a requisição HTTP atual (método, caminho relativo ao app, entradas, IP, cabeçalhos).
 * O caminho já vem sem a subpasta de publicação (ex.: "/cofre/lancamentos" → "/lancamentos").
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $input;
    /** @var array<string,mixed> */
    private array $query;
    /** @var array<string,mixed> */
    private array $files;
    /** @var array<string,string> */
    private array $server;
    /** @var array<string,string> */
    private array $cookies;
    private string $method;
    private string $path;
    private ?string $rawBody = null;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     * @param array<string,string> $server
     * @param array<string,string> $cookies
     */
    public function __construct(array $query, array $post, array $files, array $server, array $cookies, ?string $rawBody = null)
    {
        $this->query = $query;
        $this->files = $files;
        $this->server = $server;
        $this->cookies = $cookies;
        $this->rawBody = $rawBody;

        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        // Formulários HTML só enviam GET/POST: campo oculto _method permite PUT/PATCH/DELETE
        if ($method === 'POST' && isset($post['_method'])) {
            $override = strtoupper((string) $post['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }
        $this->method = $method;

        $input = $post;
        if ($this->isJson()) {
            $decoded = json_decode($this->rawBody(), true);
            if (is_array($decoded)) {
                $input = array_merge($input, $decoded);
            }
        }
        $this->input = $input;
        $this->path = $this->resolvePath();
    }

    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_FILES, $_SERVER, $_COOKIE);
    }

    private function resolvePath(): string
    {
        $uri = (string) parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = rawurldecode($uri);
        $base = (string) Config::get('app.base_path', '');
        // Sem APP_URL, ou com APP_URL que nao corresponde a URL acessada (ex.: .env ainda com a subpasta antiga):
        // deduz a subpasta pelo caminho do index.php, para o app continuar respondendo.
        if ($base === '' || !($uri === $base || str_starts_with($uri, $base . '/'))) {
            $base = self::basePathFromServer($this->server);
        }
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        // Se a requisição chegou por /public/ diretamente, também remove
        if (str_starts_with($uri, '/public/')) {
            $uri = substr($uri, 7);
        }
        $uri = '/' . trim($uri, '/');
        return $uri === '' ? '/' : $uri;
    }

    /** Subpasta deduzida do SCRIPT_NAME ("/cofre/public/index.php" ou "/cofre/index.php" → "/cofre"). */
    public static function basePathFromScript(string $script): string
    {
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if (str_ends_with($base, '/public')) {
            $base = substr($base, 0, -7);
        }
        return $base === '/' ? '' : $base;
    }

    /**
     * Subpasta real do app a partir das variaveis do servidor. Prefere SCRIPT_FILENAME relativo ao
     * DOCUMENT_ROOT (confiavel em todos os handlers de PHP) e cai para SCRIPT_NAME.
     * @param array<string,string> $server
     */
    public static function basePathFromServer(array $server): string
    {
        $filename = str_replace('\\', '/', (string) ($server['SCRIPT_FILENAME'] ?? ''));
        $docroot = rtrim(str_replace('\\', '/', (string) ($server['DOCUMENT_ROOT'] ?? '')), '/');
        if ($docroot !== '' && $filename !== '' && str_starts_with($filename, $docroot . '/')) {
            return self::basePathFromScript(substr($filename, strlen($docroot)));
        }
        return self::basePathFromScript((string) ($server['SCRIPT_NAME'] ?? ''));
    }

    /** True quando o APP_URL do .env nao corresponde a URL por onde o app esta sendo acessado. */
    public function appUrlMismatch(): bool
    {
        $configured = (string) Config::get('app.base_path', '');
        $actual = self::basePathFromServer($this->server);
        $host = strtolower((string) parse_url((string) Config::get('app.url', ''), PHP_URL_HOST));
        $actualHost = strtolower((string) ($this->server['HTTP_HOST'] ?? ''));
        $actualHost = (string) preg_replace('/:\\d+$/', '', $actualHost);
        return $configured !== $actual || ($host !== '' && $actualHost !== '' && $host !== $actualHost);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function fullUrl(): string
    {
        return rtrim((string) Config::get('app.url'), '/') . $this->path . ($this->query !== [] ? '?' . http_build_query($this->query) : '');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $value = $this->input[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    /** Valor de entrada como string (ou vazio). */
    public function str(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key): bool
    {
        $value = $this->input($key);
        return in_array($value, [true, 1, '1', 'true', 'on', 'sim'], true);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_map(static fn($v) => is_string($v) ? trim($v) : $v, $this->input);
    }

    /** @param list<string> $keys
     *  @return array<string,mixed> */
    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->input);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    /** @return array<string,mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $file;
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        return $this->cookies[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$key])) {
            return $this->server[$key];
        }
        if (strtoupper($name) === 'CONTENT-TYPE') {
            return $this->server['CONTENT_TYPE'] ?? $default;
        }
        return $default;
    }

    public function server(string $key, ?string $default = null): ?string
    {
        return $this->server[$key] ?? $default;
    }

    public function rawBody(): string
    {
        if ($this->rawBody === null) {
            $this->rawBody = (string) file_get_contents('php://input');
        }
        return $this->rawBody;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower((string) $this->header('Content-Type', '')), 'application/json');
    }

    /** Cliente espera JSON (fetch/AJAX) em vez de HTML. */
    public function wantsJson(): bool
    {
        $accept = strtolower((string) $this->header('Accept', ''));
        return $this->isJson()
            || str_contains($accept, 'application/json')
            || strtolower((string) $this->header('X-Requested-With', '')) === 'xmlhttprequest';
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && strtolower($this->server['HTTPS']) !== 'off') {
            return true;
        }
        if ($this->isTrustedProxy() && strtolower((string) $this->header('X-Forwarded-Proto', '')) === 'https') {
            return true;
        }
        return str_starts_with((string) Config::get('app.url', ''), 'https://');
    }

    private function isTrustedProxy(): bool
    {
        $remote = $this->server['REMOTE_ADDR'] ?? '';
        $trusted = (array) Config::get('security.trusted_proxies', []);
        return $remote !== '' && in_array($remote, $trusted, true);
    }

    /** IP do cliente; só honra X-Forwarded-For quando vem de um proxy listado em TRUSTED_PROXIES. */
    public function ip(): string
    {
        $remote = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($this->isTrustedProxy()) {
            $forwarded = (string) $this->header('X-Forwarded-For', '');
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return mb_substr((string) $this->header('User-Agent', ''), 0, 255);
    }

    /** Nome curto do dispositivo para telas de sessões/aparelhos (ex.: "Chrome · Android"). */
    public function deviceLabel(): string
    {
        $ua = $this->userAgent();
        $browser = match (true) {
            str_contains($ua, 'Edg/')           => 'Edge',
            str_contains($ua, 'OPR/')           => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/')       => 'Firefox',
            str_contains($ua, 'Chrome/')        => 'Chrome',
            str_contains($ua, 'Safari/')        => 'Safari',
            default                             => 'Navegador',
        };
        $os = match (true) {
            str_contains($ua, 'Android')                        => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows')                        => 'Windows',
            str_contains($ua, 'Mac OS')                         => 'macOS',
            str_contains($ua, 'Linux')                          => 'Linux',
            default                                             => 'Dispositivo',
        };
        return $browser . ' · ' . $os;
    }
}
