<?php
// app/Core/ValidationException.php
declare(strict_types=1);

namespace App\Core;

/**
 * Lançada quando a validação falha. O ErrorHandler transforma em JSON 422 ou em redirecionamento com erros.
 */
final class ValidationException extends HttpException
{
    /**
     * @param array<string,list<string>> $errors
     * @param array<string,mixed> $input
     */
    public function __construct(
        private readonly array $errors,
        private readonly array $input = [],
        private readonly ?string $backRoute = null
    ) {
        parent::__construct(422, 'Alguns campos precisam de correção.');
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> */
    public function input(): array
    {
        return $this->input;
    }

    public function backRoute(): ?string
    {
        return $this->backRoute;
    }
}
