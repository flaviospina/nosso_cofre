<?php
// app/Core/App.php
declare(strict_types=1);

namespace App\Core;

/**
 * Ponto central da aplicação: inicialização, roteador, requisição atual e nonce da CSP.
 */
final class App
{
    private static ?Router $router = null;
    private static ?Request $request = null;
    private static ?string $nonce = null;
    private static ?Route $currentRoute = null;
    /** @var array<string,string> */
    private static array $currentParams = [];

    public static function boot(string $root): void
    {
        if (!defined('APP_ROOT')) {
            define('APP_ROOT', $root);
        }
        $config = require $root . '/app/config.php';
        Config::init($config);

        // Datas internas sempre em UTC; a conversão para o fuso do usuário acontece nos helpers de exibição
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');
        setlocale(LC_ALL, 'pt_BR.UTF-8', 'pt_BR', 'C');

        ErrorHandler::register();

        self::$router = new Router();
        self::$router->setMiddlewareAliases([
            'csrf'      => Middleware\CsrfMiddleware::class,
            'auth'      => Middleware\AuthMiddleware::class,
            'guest'     => Middleware\GuestMiddleware::class,
            'role'      => Middleware\RoleMiddleware::class,
            'household' => Middleware\HouseholdMiddleware::class,
            'cron'      => Middleware\CronTokenMiddleware::class,
        ]);
        self::$router->setGlobalMiddleware(['csrf']);

        require $root . '/app/routes.php';

        Session::start();

        View::share('appName', (string) Config::get('app.name'));
        View::share('appVersion', (string) Config::get('app.version'));
    }

    public static function handle(Request $request): Response
    {
        self::$request = $request;
        try {
            return self::router()->dispatch($request);
        } catch (\Throwable $e) {
            return ErrorHandler::render($e, $request);
        }
    }

    public static function router(): Router
    {
        if (self::$router === null) {
            throw new \LogicException('Aplicação não inicializada (App::boot).');
        }
        return self::$router;
    }

    public static function request(): ?Request
    {
        return self::$request;
    }

    /** Nonce único por requisição para scripts/estilos inline permitidos pela CSP. */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        }
        return self::$nonce;
    }

    /** @param array<string,string> $params */
    public static function setCurrentRoute(Route $route, array $params): void
    {
        self::$currentRoute = $route;
        self::$currentParams = $params;
    }

    public static function currentRouteName(): ?string
    {
        return self::$currentRoute?->getName();
    }

    /** @return array<string,string> */
    public static function currentParams(): array
    {
        return self::$currentParams;
    }
}
