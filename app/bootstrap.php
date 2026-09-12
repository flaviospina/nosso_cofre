<?php
// app/bootstrap.php
// Carregado pelo front controller (public/index.php), pelo cron e pelos testes.
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Autoloader PSR-4 mínimo: App\Core\Router → app/Core/Router.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = APP_ROOT . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Bibliotecas vendorizadas (Web Push etc.) quando existirem
if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require APP_ROOT . '/vendor/autoload.php';
}

require APP_ROOT . '/app/helpers.php';

\App\Core\App::boot(APP_ROOT);
