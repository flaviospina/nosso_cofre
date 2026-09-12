<?php
// app/Core/View.php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Renderização de templates PHP puros em app/Views, com layout e "seções" (scripts, head).
 * Escape de saída é responsabilidade do template, sempre via e(). Nunca imprimir dado bruto do usuário.
 */
final class View
{
    /** @var array<string,list<string>> */
    private static array $stacks = [];
    /** @var array<string,mixed> */
    private static array $shared = [];

    /** Dados disponíveis em todas as views (ex.: usuário logado, nome do app). */
    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Renderiza "pasta/arquivo" dentro do layout informado (ou sem layout quando null).
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/base'): string
    {
        $content = self::renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::renderFile($layout, array_merge($data, ['content' => $content]));
    }

    /** @param array<string,mixed> $data */
    public static function renderFile(string $template, array $data = []): string
    {
        $base = (string) Config::get('paths.views');
        $file = $base . '/' . str_replace(['..', '\\'], '', $template) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View não encontrada: {$template}");
        }
        $data = array_merge(self::$shared, $data);
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /** Inclui um parcial (app/Views/partials/...) dentro de um template. */
    /** @param array<string,mixed> $data */
    public static function partial(string $name, array $data = []): string
    {
        return self::renderFile('partials/' . $name, $data);
    }

    /** Empilha HTML (ex.: <script nonce>) para ser impresso pelo layout com View::stack('scripts'). */
    public static function push(string $stack, string $html): void
    {
        self::$stacks[$stack][] = $html;
    }

    public static function stack(string $stack): string
    {
        return implode("\n", self::$stacks[$stack] ?? []);
    }

    /** Captura um bloco de template para um stack: View::startPush('scripts'); ... View::endPush(); */
    public static function startPush(string $stack): void
    {
        self::$stacks['__current'][] = $stack;
        ob_start();
    }

    public static function endPush(): void
    {
        $stack = array_pop(self::$stacks['__current']);
        if ($stack === null) {
            return;
        }
        self::push($stack, (string) ob_get_clean());
    }
}
