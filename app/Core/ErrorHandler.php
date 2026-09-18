<?php
// app/Core/ErrorHandler.php
declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Converte erros e exceções em respostas amigáveis (HTML ou JSON), registrando o detalhe no log.
 * Em produção o usuário nunca vê stack trace, caminho de arquivo ou SQL.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', Config::get('app.debug') ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', (string) Config::get('paths.logs') . '/php-error.log');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            // Depreciações não derrubam a página: só registram (importante ao migrar de versão do PHP)
            if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                Logger::warning('Depreciação: ' . $message, ['file' => $file . ':' . $line]);
                return true;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            self::render($e, Request::fromGlobals())->send();
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $e = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
                if (!headers_sent()) {
                    self::render($e, Request::fromGlobals())->send();
                }
            }
        });
    }

    public static function render(Throwable $e, Request $request): Response
    {
        // Validação: devolve os erros para o formulário ou em JSON
        if ($e instanceof ValidationException) {
            if ($request->wantsJson()) {
                return Response::json(false, ['errors' => $e->errors()], $e->getMessage(), 422);
            }
            Session::flashErrors($e->errors());
            Session::flashInput($e->input());
            $back = $e->backRoute();
            if ($back !== null && App::router()->has($back)) {
                return Response::redirect(App::router()->url($back));
            }
            $referer = (string) $request->header('Referer', '');
            $appUrl = (string) Config::get('app.url', '');
            if ($referer !== '' && $appUrl !== '' && ($referer === $appUrl || str_starts_with($referer, rtrim($appUrl, '/') . '/'))) {
                return Response::redirect($referer);
            }
            return Response::redirect((string) Config::get('app.base_path', '') . $request->path());
        }

        $status = $e instanceof HttpException ? $e->getStatus() : 500;
        $message = $e instanceof HttpException ? $e->getMessage() : HttpException::defaultMessage(500);
        $headers = $e instanceof HttpException ? $e->getHeaders() : [];

        $context = [
            'status' => $status,
            'method' => $request->method(),
            'path'   => $request->path(),
            'ip'     => $request->ip(),
            'user'   => Session::get('user_id'),
        ];
        if ($status >= 500) {
            $context['exception'] = $e;
            $context['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 12);
            Logger::error($e->getMessage(), $context);
        } elseif (in_array($status, [401, 403, 419, 429], true)) {
            Logger::security($e->getMessage(), $context);
        } elseif ($status !== 404) {
            Logger::warning($e->getMessage(), $context);
        }

        if ($request->wantsJson()) {
            $data = null;
            if (Config::get('app.debug') && $status >= 500) {
                $data = ['exception' => get_class($e), 'detail' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()];
            }
            $response = Response::json(false, $data, $message, $status);
        } else {
            $response = Response::html(self::renderPage($status, $message, $e), $status);
        }
        foreach ($headers as $name => $value) {
            $response->withHeader($name, $value);
        }
        return $response;
    }

    private static function renderPage(int $status, string $message, Throwable $e): string
    {
        $template = match (true) {
            $status === 404 => 'errors/404',
            $status === 403 || $status === 401 => 'errors/403',
            $status === 419 => 'errors/419',
            $status === 429 => 'errors/429',
            default => 'errors/500',
        };
        $data = [
            'title'   => "Erro {$status}",
            'status'  => $status,
            'message' => $message,
            'debug'   => Config::get('app.debug') ? [
                'exception' => get_class($e),
                'detail'    => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ] : null,
        ];
        try {
            $layout = Auth::check() ? 'layouts/base' : 'layouts/base';
            return View::render($template, $data, $layout);
        } catch (Throwable $inner) {
            // Último recurso: página mínima sem dependências
            Logger::critical('Falha ao renderizar página de erro', ['exception' => $inner]);
            return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Erro ' . $status . '</title></head>'
                . '<body style="font-family:sans-serif;padding:2rem"><h1>Erro ' . $status . '</h1><p>'
                . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
        }
    }
}
