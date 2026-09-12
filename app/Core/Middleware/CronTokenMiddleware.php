<?php
// app/Core/Middleware/CronTokenMiddleware.php
declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * Protege rotas chamadas pelo cron do cPanel por URL: exige ?token= igual ao CRON_TOKEN do .env.
 */
final class CronTokenMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, array $args = []): Response
    {
        $expected = (string) Config::get('cron.token', '');
        $given = (string) ($request->query('token') ?? $request->header('X-Cron-Token', ''));
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            Logger::security('Chamada de cron com token inválido', ['ip' => $request->ip(), 'path' => $request->path()]);
            throw new HttpException(403, 'Token do cron inválido.');
        }
        return $next($request);
    }
}
