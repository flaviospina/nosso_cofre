<?php
// app/Core/Middleware/Middleware.php
declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;
use Closure;

/**
 * Contrato de middleware. $args vem do sufixo da rota (ex.: "role:owner,admin" → ['owner','admin']).
 */
interface Middleware
{
    /** @param list<string> $args */
    public function handle(Request $request, Closure $next, array $args = []): Response;
}
