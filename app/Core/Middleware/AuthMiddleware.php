<?php
// app/Core/Middleware/AuthMiddleware.php
declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\App;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Closure;

/**
 * Exige usuário logado. Guarda a URL pretendida para voltar após o login.
 */
final class AuthMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, array $args = []): Response
    {
        if (!Auth::check()) {
            if ($request->wantsJson()) {
                throw new HttpException(401);
            }
            if ($request->method() === 'GET') {
                Session::set('_intended', $request->path());
            }
            Session::flash('info', 'Entre para continuar.');
            $router = App::router();
            return Response::redirect($router->has('auth.login') ? $router->url('auth.login') : $router->url('home'));
        }
        return $next($request);
    }
}
