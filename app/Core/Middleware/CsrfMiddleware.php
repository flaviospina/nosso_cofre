<?php
// app/Core/Middleware/CsrfMiddleware.php
declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * Exige token CSRF em POST/PUT/PATCH/DELETE. Rotas chamadas por sistemas externos (cron por URL)
 * usam o middleware "cron" no lugar deste, via lista de exceção.
 */
final class CsrfMiddleware implements Middleware
{
    /** @var list<string> caminhos isentos (prefixo) */
    private const EXEMPT = ['/cron/'];

    public function handle(Request $request, Closure $next, array $args = []): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $exempt = false;
            foreach (self::EXEMPT as $prefix) {
                if (str_starts_with($request->path(), $prefix)) {
                    $exempt = true;
                    break;
                }
            }
            if (!$exempt && !Csrf::validateRequest($request)) {
                Logger::security('Token CSRF inválido', ['path' => $request->path(), 'ip' => $request->ip()]);
                throw new HttpException(419);
            }
        }
        return $next($request);
    }
}
