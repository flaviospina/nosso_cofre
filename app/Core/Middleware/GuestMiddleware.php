<?php
// app/Core/Middleware/GuestMiddleware.php
declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\App;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * Só para visitantes (login, cadastro). Usuário logado é mandado para o painel.
 */
final class GuestMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, array $args = []): Response
    {
        if (Auth::check()) {
            $router = App::router();
            return Response::redirect($router->has('dashboard') ? $router->url('dashboard') : $router->url('home'));
        }
        return $next($request);
    }
}
