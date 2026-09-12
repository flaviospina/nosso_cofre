<?php
// tools/dev-server.php — roteador para o servidor embutido do PHP (SÓ desenvolvimento local).
// Emula o .htaccess: serve arquivos reais de public/ e manda o resto para o front controller.
// Uso: php -S 127.0.0.1:8080 tools/dev-server.php   (APP_URL no .env pode ter subpasta, ex.: http://127.0.0.1:8080/cofre)
declare(strict_types=1);

$root = dirname(__DIR__);
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Descobre a subpasta a partir do APP_URL do .env para servir assets em /cofre/assets/...
$base = '';
if (is_file($root . '/.env')) {
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), 'APP_URL=')) {
            $base = rtrim((string) parse_url(trim(substr(trim($line), 8), " \"'"), PHP_URL_PATH), '/');
        }
    }
}
$relative = $path;
if ($base !== '' && str_starts_with($relative, $base)) {
    $relative = substr($relative, strlen($base));
}
$file = $root . '/public' . $relative;
if ($relative !== '' && $relative !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png',
        'webmanifest' => 'application/manifest+json', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'ico' => 'image/x-icon',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    readfile($file);
    return true;
}
$_SERVER['SCRIPT_NAME'] = $base . '/public/index.php';
require $root . '/public/index.php';
