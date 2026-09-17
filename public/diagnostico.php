<?php
// public/diagnostico.php — diagnóstico da instalação SEM passar pelo roteador.
// Abra direto: https://SEU-DOMINIO/SUBPASTA/public/diagnostico.php
// Só funciona enquanto o APP_KEY do .env estiver vazio ou APP_DEBUG=true. Apague este arquivo depois de usar.
declare(strict_types=1);

// O teste de sessão precisa rodar antes de qualquer saída (cookies são cabeçalhos)
$sessionResult = 'FALHOU';
try {
    session_save_path(dirname(__DIR__) . '/storage/sessions');
    session_name('nc_diag');
    if (@session_start()) {
        $_SESSION['x'] = 1;
        session_write_close();
        $sessionResult = 'OK';
    }
} catch (Throwable $e) {
    $sessionResult = 'ERRO: ' . $e->getMessage();
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
@ini_set('display_errors', '1');
error_reporting(E_ALL);
@set_time_limit(25);
while (ob_get_level() > 0) {
    ob_end_flush();
}

$root = dirname(__DIR__);
$envFile = $root . '/.env';
$env = [];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
}
$allowed = ($env['APP_KEY'] ?? '') === '' || in_array(strtolower($env['APP_DEBUG'] ?? ''), ['1', 'true', 'on'], true);
if (!$allowed) {
    http_response_code(404);
    echo "Diagnóstico desativado (APP_KEY preenchido e APP_DEBUG=false). Apague este arquivo.\n";
    exit;
}

function step(string $label, callable $fn): void
{
    echo str_pad($label, 52, '.');
    flush();
    $t = microtime(true);
    try {
        $r = $fn();
        echo ' ' . ($r === true ? 'OK' : ($r === false ? 'FALHOU' : (string) $r));
    } catch (Throwable $e) {
        echo ' ERRO: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
    printf("  [%.0f ms]\n", (microtime(true) - $t) * 1000);
    flush();
}

echo "NOSSO COFRE — DIAGNÓSTICO  " . gmdate('c') . "\n\n";
echo "Servidor: " . ($_SERVER['SERVER_SOFTWARE'] ?? '?') . "\n";
echo "PHP: " . PHP_VERSION . " (" . PHP_SAPI . ")\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? '?') . "\n";
echo "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? '?') . "\n";
echo "Pasta do projeto: " . $root . "\n";
echo "APP_URL no .env: " . ($env['APP_URL'] ?? '(não definido)') . "\n\n";

step('PHP >= 8.2', static fn() => version_compare(PHP_VERSION, '8.2.0', '>='));
foreach (['pdo_mysql', 'openssl', 'mbstring', 'json', 'fileinfo', 'gd', 'zip', 'curl', 'gmp', 'bcmath'] as $ext) {
    step("Extensão {$ext}", static fn() => extension_loaded($ext));
}
step('Argon2id', static fn() => defined('PASSWORD_ARGON2ID'));
step('Arquivo .env existe e é legível', static fn() => is_file($envFile) && is_readable($envFile));
step('Pasta app/ existe', static fn() => is_dir($root . '/app/Core'));
foreach (['logs', 'cache', 'uploads', 'exports', 'backups', 'sessions'] as $dir) {
    step("storage/{$dir} gravável", static fn() => is_dir($root . '/storage/' . $dir) && is_writable($root . '/storage/' . $dir));
}
step('Gravar arquivo em storage/logs', static function () use ($root) {
    $f = $root . '/storage/logs/diagnostico.tmp';
    $ok = @file_put_contents($f, 'x', LOCK_EX) === 1;
    @unlink($f);
    return $ok;
});
step('random_bytes(32)', static fn() => strlen(random_bytes(32)) === 32);
step('session_start() em storage/sessions', static fn() => $sessionResult);
step('Conexão MySQL (se DB_* preenchidos)', static function () use ($env) {
    if (($env['DB_NAME'] ?? '') === '') {
        return 'pulado (DB_NAME vazio)';
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? 'localhost', $env['DB_PORT'] ?? '3306', $env['DB_NAME']);
    $pdo = new PDO($dsn, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    return 'OK (' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ')';
});
step('Carregar o framework (bootstrap)', static function () use ($root) {
    require $root . '/app/bootstrap.php';
    return true;
});
step('Roteador responde /instalar', static function () {
    $server = $_SERVER;
    $server['REQUEST_METHOD'] = 'GET';
    $server['REQUEST_URI'] = rtrim((string) \App\Core\Config::get('app.base_path', ''), '/') . '/instalar';
    $request = new \App\Core\Request([], [], [], $server, []);
    $response = \App\Core\App::handle($request);
    return 'HTTP ' . $response->status() . ', ' . strlen($response->body()) . ' bytes';
});

echo "\nSe todas as linhas acima apareceram, o PHP e o código estão funcionando; um 504/500 na URL amigável é problema de .htaccess/reescrita.\n";
echo "Se a página parou em alguma linha, o travamento está naquele passo.\n";
echo "Apague public/diagnostico.php depois de usar.\n";
