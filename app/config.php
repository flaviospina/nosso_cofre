<?php
// app/config.php
// Lê o .env (sem biblioteca) e devolve o array de configuração usado por App\Core\Config.
declare(strict_types=1);

use App\Core\Env;

Env::load(APP_ROOT . '/.env');

$appUrl = rtrim(Env::get('APP_URL', ''), '/');
$basePath = '';
if ($appUrl !== '') {
    $basePath = rtrim((string) parse_url($appUrl, PHP_URL_PATH), '/');
}

return [
    'app' => [
        'name'     => Env::get('APP_NAME', 'Nosso Cofre'),
        'env'      => Env::get('APP_ENV', 'production'),
        'debug'    => Env::bool('APP_DEBUG', false),
        'url'      => $appUrl,
        // Caminho da subpasta (ex.: "/cofre"). Vazio quando publicado na raiz do domínio.
        'base_path'=> $basePath,
        'key'      => Env::get('APP_KEY', ''),
        'timezone' => Env::get('APP_TIMEZONE', 'America/Sao_Paulo'),
        'locale'   => 'pt_BR',
        'version'  => '2.0.0',
    ],
    'db' => [
        'host'    => Env::get('DB_HOST', 'localhost'),
        'port'    => (int) Env::get('DB_PORT', '3306'),
        'name'    => Env::get('DB_NAME', ''),
        'user'    => Env::get('DB_USER', ''),
        'pass'    => Env::get('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'host'         => Env::get('MAIL_HOST', 'localhost'),
        'port'         => (int) Env::get('MAIL_PORT', '465'),
        'encryption'   => Env::get('MAIL_ENCRYPTION', 'ssl'),
        'user'         => Env::get('MAIL_USER', ''),
        'pass'         => Env::get('MAIL_PASS', ''),
        'from_address' => Env::get('MAIL_FROM_ADDRESS', 'cofre@localhost'),
        'from_name'    => Env::get('MAIL_FROM_NAME', 'Nosso Cofre'),
    ],
    'security' => [
        'session_cookie'         => 'nc_session',
        'session_idle_minutes'   => (int) Env::get('SESSION_IDLE_MINUTES', '30'),
        'session_absolute_hours' => (int) Env::get('SESSION_ABSOLUTE_HOURS', '12'),
        'remember_days'          => (int) Env::get('REMEMBER_DAYS', '30'),
        'trusted_proxies'        => array_values(array_filter(array_map('trim', explode(',', Env::get('TRUSTED_PROXIES', ''))))),
        'password_min_length'    => 10,
    ],
    'cron' => [
        'token' => Env::get('CRON_TOKEN', ''),
    ],
    'backup' => [
        'key'            => Env::get('BACKUP_KEY', ''),
        'retention_days' => (int) Env::get('BACKUP_RETENTION_DAYS', '30'),
    ],
    'vapid' => [
        'public'  => Env::get('VAPID_PUBLIC_KEY', ''),
        'private' => Env::get('VAPID_PRIVATE_KEY', ''),
        'subject' => Env::get('VAPID_SUBJECT', ''),
    ],
    'legal' => [
        'controller_name'     => Env::get('CONTROLLER_NAME', ''),
        'controller_document' => Env::get('CONTROLLER_DOCUMENT', ''),
        'controller_email'    => Env::get('CONTROLLER_EMAIL', ''),
        'dpo_name'            => Env::get('DPO_NAME', ''),
        'dpo_email'           => Env::get('DPO_EMAIL', ''),
    ],
    'log' => [
        'level'          => Env::get('LOG_LEVEL', 'info'),
        'retention_days' => (int) Env::get('LOG_RETENTION_DAYS', '30'),
    ],
    'paths' => [
        'root'     => APP_ROOT,
        'app'      => APP_ROOT . '/app',
        'views'    => APP_ROOT . '/app/Views',
        'public'   => defined('PUBLIC_ROOT') ? PUBLIC_ROOT : APP_ROOT . '/public',
        'storage'  => APP_ROOT . '/storage',
        'logs'     => APP_ROOT . '/storage/logs',
        'cache'    => APP_ROOT . '/storage/cache',
        'uploads'  => APP_ROOT . '/storage/uploads',
        'exports'  => APP_ROOT . '/storage/exports',
        'backups'  => APP_ROOT . '/storage/backups',
        'sessions' => APP_ROOT . '/storage/sessions',
        'legal'    => APP_ROOT . '/legal',
        'vendor'   => APP_ROOT . '/vendor',
    ],
];
