<?php
// app/Core/Router.php
declare(strict_types=1);

namespace App\Core;

use App\Core\Middleware\Middleware;
use Closure;

/**
 * Roteador com rotas nomeadas, parâmetros ({id}, {year:\d{4}}), grupos com prefixo e middleware.
 *
 * Uso:
 *   $router->get('/lancamentos/editar/{id:\d+}', [TransactionController::class, 'edit'], 'transactions.edit');
 *   $router->group(['prefix' => '/familia', 'middleware' => ['auth', 'role:owner,admin']], function (Router $r) { ... });
 *   route('transactions.edit', ['id' => 123]) → "/cofre/lancamentos/editar/123"
 */
final class Router
{
    /** @var array<string,list<Route>> */
    private array $routes = ['GET' => [], 'POST' => [], 'PUT' => [], 'PATCH' => [], 'DELETE' => []];
    /** @var array<string,Route> */
    private array $named = [];
    /** @var list<array{prefix:string,middleware:list<string>}> */
    private array $groupStack = [];
    /** @var list<string> */
    private array $globalMiddleware = [];
    /** @var array<string,class-string<Middleware>> */
    private array $middlewareAliases = [];

    /** @param array<string,class-string<Middleware>> $aliases */
    public function setMiddlewareAliases(array $aliases): void
    {
        $this->middlewareAliases = $aliases;
    }

    /** @param list<string> $middleware */
    public function setGlobalMiddleware(array $middleware): void
    {
        $this->globalMiddleware = $middleware;
    }

    public function get(string $path, callable|array $handler, ?string $name = null): Route
    {
        return $this->add(['GET'], $path, $handler, $name);
    }

    public function post(string $path, callable|array $handler, ?string $name = null): Route
    {
        return $this->add(['POST'], $path, $handler, $name);
    }

    public function put(string $path, callable|array $handler, ?string $name = null): Route
    {
        return $this->add(['PUT'], $path, $handler, $name);
    }

    public function patch(string $path, callable|array $handler, ?string $name = null): Route
    {
        return $this->add(['PATCH'], $path, $handler, $name);
    }

    public function delete(string $path, callable|array $handler, ?string $name = null): Route
    {
        return $this->add(['DELETE'], $path, $handler, $name);
    }

    /** @param list<string> $methods */
    public function match(array $methods, string $path, callable|array $handler, ?string $name = null): Route
    {
        return $this->add($methods, $path, $handler, $name);
    }

    /** @param array{prefix?:string,middleware?:list<string>|string} $attributes */
    public function group(array $attributes, Closure $callback): void
    {
        $middleware = $attributes['middleware'] ?? [];
        $this->groupStack[] = [
            'prefix'     => $attributes['prefix'] ?? '',
            'middleware' => is_array($middleware) ? $middleware : [$middleware],
        ];
        $callback($this);
        array_pop($this->groupStack);
    }

    /** @param list<string> $methods */
    private function add(array $methods, string $path, callable|array $handler, ?string $name): Route
    {
        $prefix = '';
        $middleware = [];
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
            $middleware = array_merge($middleware, $group['middleware']);
        }
        $fullPath = '/' . trim($prefix . '/' . trim($path, '/'), '/');
        $route = new Route($methods, $fullPath, $handler, $middleware);
        foreach ($methods as $method) {
            $this->routes[strtoupper($method)][] = $route;
        }
        if ($name !== null) {
            $this->name($route, $name);
        }
        $route->setRouter($this);
        return $route;
    }

    public function name(Route $route, string $name): void
    {
        $this->named[$name] = $route;
    }

    public function has(string $name): bool
    {
        return isset($this->named[$name]);
    }

    /**
     * Gera a URL (com subpasta) de uma rota nomeada.
     * @param array<string,int|string> $params
     * @param array<string,mixed> $query
     */
    public function url(string $name, array $params = [], array $query = []): string
    {
        if (!isset($this->named[$name])) {
            throw new \InvalidArgumentException("Rota nomeada inexistente: {$name}");
        }
        $path = $this->named[$name]->buildPath($params);
        $url = (string) Config::get('app.base_path', '') . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $url === '' ? '/' : $url;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();
        $matched = null;
        $params = [];

        foreach ($this->routes[$method] ?? [] as $route) {
            $result = $route->matches($path);
            if ($result !== null) {
                $matched = $route;
                $params = $result;
                break;
            }
        }

        if ($matched === null) {
            // Diferencia 404 de 405 para o mesmo caminho em outro método
            foreach ($this->routes as $otherMethod => $routes) {
                if ($otherMethod === $method) {
                    continue;
                }
                foreach ($routes as $route) {
                    if ($route->matches($path) !== null) {
                        throw new HttpException(405);
                    }
                }
            }
            throw new HttpException(404);
        }

        App::setCurrentRoute($matched, $params);
        $pipeline = array_values(array_unique(array_merge($this->globalMiddleware, $matched->middleware())));
        $handler = $matched->handler();

        $core = function (Request $req) use ($handler, $params): Response {
            return $this->normalize($this->invoke($handler, $req, $params));
        };

        $next = array_reduce(
            array_reverse($pipeline),
            function (Closure $next, string $spec): Closure {
                return function (Request $req) use ($next, $spec): Response {
                    [$alias, $args] = array_pad(explode(':', $spec, 2), 2, '');
                    $class = $this->middlewareAliases[$alias] ?? $alias;
                    if (!class_exists($class)) {
                        throw new \LogicException("Middleware desconhecido: {$spec}");
                    }
                    /** @var Middleware $instance */
                    $instance = new $class();
                    return $instance->handle($req, $next, $args === '' ? [] : explode(',', $args));
                };
            },
            $core
        );

        return $next($request);
    }

    /** @param array<string,string> $params */
    private function invoke(callable|array $handler, Request $request, array $params): mixed
    {
        if (is_array($handler) && is_string($handler[0])) {
            [$class, $method] = $handler;
            if (!class_exists($class) || !method_exists($class, $method)) {
                throw new \LogicException("Controller ou método inexistente: {$class}::{$method}");
            }
            $controller = new $class($request);
            return $controller->{$method}(...array_values($params));
        }
        return $handler($request, ...array_values($params));
    }

    private function normalize(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_string($result)) {
            return Response::html($result);
        }
        if (is_array($result)) {
            return Response::json(true, $result);
        }
        if ($result === null) {
            return Response::noContent();
        }
        throw new \LogicException('Retorno de rota inválido: esperado Response, string, array ou null.');
    }

    /** @return array<string,list<Route>> */
    public function routes(): array
    {
        return $this->routes;
    }
}
