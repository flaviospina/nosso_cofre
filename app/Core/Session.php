<?php
// app/Core/Session.php
declare(strict_types=1);

namespace App\Core;

/**
 * Sessão PHP com cookie endurecido (HttpOnly, Secure, SameSite=Strict, caminho da subpasta),
 * expiração por inatividade e absoluta, e mensagens flash.
 * Os arquivos ficam em storage/sessions (fora da pasta pública). Na fase 2 entra o handler em banco
 * para a tela "Sessões ativas".
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        if (PHP_SAPI === 'cli') {
            self::$started = true;
            return;
        }

        $useDatabase = (string) Config::get('security.session_driver', 'database') === 'database' && Database::isConfigured();
        if ($useDatabase) {
            session_set_save_handler(new DatabaseSessionHandler(), true);
        } else {
            $savePath = (string) Config::get('paths.sessions');
            if (is_dir($savePath) && is_writable($savePath)) {
                session_save_path($savePath);
            }
        }

        $basePath = (string) Config::get('app.base_path', '');
        $secure = str_starts_with((string) Config::get('app.url', ''), 'https://')
            || (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off');

        session_name((string) Config::get('security.session_cookie', 'nc_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => ($basePath === '' ? '/' : $basePath . '/'),
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        // O cron de retenção limpa sessões velhas; aqui só garantimos o GC nativo razoável
        ini_set('session.gc_maxlifetime', (string) (max(1, (int) Config::get('security.session_absolute_hours', 12)) * 3600));

        session_start();
        self::$started = true;
        self::enforceTimeouts();
    }

    /** Expira por inatividade (configurável por usuário) e por tempo absoluto. */
    private static function enforceTimeouts(): void
    {
        $now = time();
        $idleMinutes = (int) ($_SESSION['_idle_minutes'] ?? Config::get('security.session_idle_minutes', 30));
        $absoluteHours = (int) Config::get('security.session_absolute_hours', 12);

        $created = (int) ($_SESSION['_created_at'] ?? 0);
        $last = (int) ($_SESSION['_last_activity'] ?? 0);

        $expired = false;
        if ($created > 0 && $now - $created > $absoluteHours * 3600) {
            $expired = true;
        }
        if ($last > 0 && $now - $last > $idleMinutes * 60) {
            $expired = true;
        }
        if ($expired) {
            $hadUser = isset($_SESSION['user_id']);
            self::destroy();
            session_start();
            if ($hadUser) {
                self::flash('warning', 'Sua sessão expirou por inatividade. Entre novamente.');
            }
        }
        if (!isset($_SESSION['_created_at'])) {
            $_SESSION['_created_at'] = $now;
        }
        if (!self::isPassiveRequest()) {
            $_SESSION['_last_activity'] = $now;
        }
    }

    /** Requisições automáticas (polling de avisos) não renovam a inatividade. */
    private static function isPassiveRequest(): bool
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return str_ends_with($path, '/avisos/novos');
    }

    public static function setIdleMinutes(int $minutes): void
    {
        $_SESSION['_idle_minutes'] = max(5, min(240, $minutes));
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_created_at'] = time();
        }
    }

    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
            session_destroy();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function id(): string
    {
        return session_id();
    }

    /** Mensagem flash (aparece uma única vez na próxima página). Tipos: success, info, warning, danger. */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public static function pullFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($flashes) ? $flashes : [];
    }

    /** Guarda a entrada do formulário para repopular após erro de validação. */
    /** @param array<string,mixed> $input */
    public static function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['current_password'], $input['_token'], $input['_method']);
        $_SESSION['_old_input'] = $input;
    }

    /** @return array<string,mixed> */
    public static function pullOldInput(): array
    {
        $old = $_SESSION['_old_input'] ?? [];
        unset($_SESSION['_old_input']);
        return is_array($old) ? $old : [];
    }

    /** @param array<string,list<string>> $errors */
    public static function flashErrors(array $errors): void
    {
        $_SESSION['_errors'] = $errors;
    }

    /** @return array<string,list<string>> */
    public static function pullErrors(): array
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        return is_array($errors) ? $errors : [];
    }
}
