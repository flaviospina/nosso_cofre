<?php
// app/Core/Route.php
declare(strict_types=1);

namespace App\Core;

/**
 * Uma rota registrada: métodos, padrão com parâmetros, handler, middleware e nome.
 */
final class Route
{
    private string $regex;
    /** @var list<string> */
    private array $paramNames = [];
    private ?string $name = null;
    private ?Router $router = null;

    /**
     * @param list<string> $methods
     * @param callable|array{0:class-string,1:string} $handler
     * @param list<string> $middleware
     */
    public function __construct(
        private readonly array $methods,
        private readonly string $path,
        private $handler,
        private array $middleware = []
    ) {
        $this->regex = $this->compile($path);
    }

    private function compile(string $path): string
    {
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})+))?\}/',
            function (array $m): string {
                $this->paramNames[] = $m[1];
                $constraint = $m[2] ?? '[^/]+';
                return '(' . $constraint . ')';
            },
            $path
        );
        return '#^' . $regex . '$#u';
    }

    /** @return array<string,string>|null */
    public function matches(string $path): ?array
    {
        if (!preg_match($this->regex, $path, $m)) {
            return null;
        }
        array_shift($m);
        $params = [];
        foreach ($this->paramNames as $i => $name) {
            $params[$name] = $m[$i] ?? '';
        }
        return $params;
    }

    /** @param array<string,int|string> $params */
    public function buildPath(array $params): string
    {
        $missing = [];
        $path = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::(?:[^{}]|\{[^{}]*\})+)?\}/',
            static function (array $m) use ($params, &$missing): string {
                if (!array_key_exists($m[1], $params)) {
                    $missing[] = $m[1];
                    return '';
                }
                return rawurlencode((string) $params[$m[1]]);
            },
            $this->path
        );
        if ($missing !== []) {
            throw new \InvalidArgumentException('Parâmetros faltando para a rota ' . $this->path . ': ' . implode(', ', $missing));
        }
        return (string) $path;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        $this->router?->name($this, $name);
        return $this;
    }

    /** @param list<string>|string $middleware */
    public function middleware(array|string|null $middleware = null): self|array
    {
        if ($middleware === null) {
            return $this->middleware;
        }
        $this->middleware = array_merge($this->middleware, is_array($middleware) ? $middleware : [$middleware]);
        return $this;
    }

    public function setRouter(Router $router): void
    {
        $this->router = $router;
        if ($this->name !== null) {
            $router->name($this, $this->name);
        }
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return callable|array{0:class-string,1:string} */
    public function handler(): callable|array
    {
        return $this->handler;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return $this->methods;
    }
}
