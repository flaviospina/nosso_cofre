<?php
// app/Core/Middleware/AdminMiddleware.php
declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Auth;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * Área do controlador (incidentes LGPD): só usuários cujo e-mail está em ADMIN_EMAILS no .env.
 */
final class AdminMiddleware implements Middleware
{
    public static function isAdmin(): bool
    {
        $user = Auth::user();
        if ($user === null) {
            return false;
        }
        $emails = array_map('mb_strtolower', (array) Config::get('admin.emails', []));
        return in_array(mb_strtolower((string) $user['email']), $emails, true);
    }

    public function handle(Request $request, Closure $next, array $args = []): Response
    {
        if (!self::isAdmin()) {
            throw new HttpException(404);
        }
        return $next($request);
    }
}
