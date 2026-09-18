<?php
// app/Core/Middleware/TwoFactorRequiredMiddleware.php
declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\App;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Closure;

/**
 * Responsável/administrador de lar familiar precisa ativar o 2FA antes de usar o restante do sistema.
 * As rotas de conta (onde o 2FA é configurado) não recebem este middleware.
 */
final class TwoFactorRequiredMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, array $args = []): Response
    {
        if (Auth::check() && Auth::twoFactorRequired()) {
            Session::flash('warning', 'Como responsável ou administrador de um lar familiar, você precisa ativar a verificação em duas etapas.');
            return Response::redirect(App::router()->url('account.two_factor'));
        }
        return $next($request);
    }
}
