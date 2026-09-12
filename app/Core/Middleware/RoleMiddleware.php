<?php
// app/Core/Middleware/RoleMiddleware.php
declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * Exige um dos papéis no lar ativo: "role:owner,admin". Sem lar ativo → 403.
 */
final class RoleMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, array $args = []): Response
    {
        if (!Auth::check()) {
            throw new HttpException(401);
        }
        if ($args === [] || !Auth::hasRole(...$args)) {
            throw new HttpException(403, 'Seu papel no lar não permite esta ação.');
        }
        return $next($request);
    }
}
