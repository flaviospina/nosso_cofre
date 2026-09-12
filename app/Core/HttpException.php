<?php
// app/Core/HttpException.php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Exceção com status HTTP. A mensagem é sempre segura para mostrar ao usuário (pt-BR).
 */
class HttpException extends RuntimeException
{
    /** @param array<string,string> $headers */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string,string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Requisição inválida.',
            401 => 'Você precisa entrar para acessar esta página.',
            403 => 'Você não tem permissão para acessar este recurso.',
            404 => 'Página não encontrada.',
            405 => 'Método não permitido.',
            419 => 'Sua sessão expirou ou o formulário é inválido. Recarregue a página e tente novamente.',
            422 => 'Alguns campos precisam de correção.',
            429 => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.',
            503 => 'Serviço temporariamente indisponível.',
            default => 'Ocorreu um erro inesperado. Já registramos o problema.',
        };
    }
}
