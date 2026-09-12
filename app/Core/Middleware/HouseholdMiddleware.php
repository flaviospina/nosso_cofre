<?php
// app/Core/Middleware/HouseholdMiddleware.php
declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\App;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Closure;

/**
 * Exige um lar ativo (usuário que ainda não concluiu o onboarding é levado para ele).
 */
final class HouseholdMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, array $args = []): Response
    {
        if (Auth::householdId() === null) {
            $router = App::router();
            Session::flash('info', 'Conclua a configuração inicial para continuar.');
            return Response::redirect($router->has('onboarding.start') ? $router->url('onboarding.start') : $router->url('home'));
        }
        return $next($request);
    }
}
