<?php
// app/routes.php
// Todas as rotas da aplicação. Cada fase acrescenta seu bloco aqui.
declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\SystemController;
use App\Core\Router;

/** @var Router $router */
$router = \App\Core\App::router();

// --- Fase 1: fundação ---
$router->get('/', [HomeController::class, 'index'], 'home');
$router->get('/offline', [SystemController::class, 'offline'], 'system.offline');
$router->get('/saude', [SystemController::class, 'health'], 'system.health');
$router->get('/instalar', [SystemController::class, 'install'], 'system.install');

// Cron por URL (o cPanel também pode chamar cron/run.php direto por linha de comando)
$router->get('/cron/run', [SystemController::class, 'cron'], 'system.cron')->middleware('cron');
