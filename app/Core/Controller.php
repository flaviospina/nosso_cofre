<?php
// app/Core/Controller.php
declare(strict_types=1);

namespace App\Core;

/**
 * Controller base: acesso à requisição, renderização, respostas JSON, redirecionamentos, validação e flash.
 */
abstract class Controller
{
    public function __construct(protected Request $request)
    {
    }

    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], ?string $layout = 'layouts/base', int $status = 200): Response
    {
        return Response::html(View::render($template, $data, $layout), $status);
    }

    protected function json(bool $ok, mixed $data = null, string $message = '', int $status = 200): Response
    {
        return Response::json($ok, $data, $message, $status);
    }

    /** @param array<string,int|string> $params
     *  @param array<string,mixed> $query */
    protected function redirectRoute(string $name, array $params = [], array $query = []): Response
    {
        return Response::redirect(App::router()->url($name, $params, $query));
    }

    protected function redirect(string $url): Response
    {
        // Só permite redirecionar dentro do próprio app (evita open redirect)
        $base = (string) Config::get('app.base_path', '');
        if (!str_starts_with($url, $base . '/') && $url !== ($base === '' ? '/' : $base)) {
            $url = $base === '' ? '/' : $base . '/';
        }
        return Response::redirect($url);
    }

    protected function back(string $fallbackRoute = 'home'): Response
    {
        $referer = (string) $this->request->header('Referer', '');
        $appUrl = (string) Config::get('app.url', '');
        if ($referer !== '' && $appUrl !== '' && ($referer === $appUrl || str_starts_with($referer, rtrim($appUrl, '/') . '/'))) {
            return Response::redirect($referer);
        }
        return $this->redirectRoute($fallbackRoute);
    }

    protected function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    /**
     * Valida a entrada. Em caso de erro: JSON 422 (fetch) ou redireciona de volta com erros e valores antigos.
     * @param array<string,string|list<string>> $rules
     * @param array<string,string> $labels
     * @return array<string,mixed> dados validados e normalizados
     */
    protected function validate(array $rules, array $labels = [], ?string $backRoute = null): array
    {
        $validator = Validator::make($this->request->all(), $rules, $labels);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors(), $this->request->all(), $backRoute);
        }
        return $validator->validated();
    }

    /** Aborta com um erro HTTP (mensagem sempre segura, em pt-BR). */
    protected function abort(int $status, string $message = ''): never
    {
        throw new HttpException($status, $message);
    }
}
