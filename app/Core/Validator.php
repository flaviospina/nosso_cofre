<?php
// app/Core/Validator.php
declare(strict_types=1);

namespace App\Core;

use DateTime;

/**
 * Validação server-side com regras em string ("required|email|max:190") e mensagens em pt-BR.
 *
 * Regras: required, nullable, string, email, min:n, max:n, between:a,b, integer, numeric, boolean, accepted,
 *         in:a,b,c, not_in:a,b, regex:/.../, confirmed, same:campo, different:campo, date (Y-m-d),
 *         date_br (dd/mm/aaaa → normaliza para Y-m-d), money (R$ 1.234,56 → "1234.56"), color (#rrggbb),
 *         alpha_dash, url, array, json, digits:n, unique:tabela,coluna[,idIgnorado[,colunaId]], exists:tabela,coluna,
 *         timezone, password (força mínima), cpf.
 *
 * validated() devolve só os campos com regra, já normalizados (money/date_br convertidos, strings aparadas).
 */
final class Validator
{
    /** @var array<string,list<string>> */
    private array $errors = [];
    /** @var array<string,mixed> */
    private array $validated = [];
    private bool $ran = false;

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|list<string>> $rules
     * @param array<string,string> $labels
     */
    private function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $labels
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string|list<string>> $rules
     * @param array<string,string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function fails(): bool
    {
        $this->run();
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return !$this->fails();
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        $this->run();
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors() as $messages) {
            return $messages[0] ?? null;
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        $this->run();
        return $this->validated;
    }

    private function run(): void
    {
        if ($this->ran) {
            return;
        }
        $this->ran = true;
        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $rules = array_values(array_filter(array_map('trim', $rules)));
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $nullable = in_array('nullable', $rules, true);
            $required = in_array('required', $rules, true);
            $empty = $value === null || $value === '' || $value === [];

            if ($empty) {
                if ($required) {
                    $this->addError($field, 'O campo :campo é obrigatório.');
                    continue;
                }
                $this->validated[$field] = $nullable || !isset($this->data[$field]) ? null : $value;
                continue;
            }

            $normalized = $value;
            foreach ($rules as $rule) {
                if ($rule === 'required' || $rule === 'nullable' || $rule === 'string') {
                    continue;
                }
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, '');
                [$ok, $value] = $this->check($name, $arg, $field, $normalized);
                if (!$ok) {
                    break; // primeiro erro por campo é suficiente
                }
                if ($value !== self::UNCHANGED) {
                    $normalized = $value; // regra normalizou o valor (money, date_br, boolean...)
                }
            }
            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $normalized;
            }
        }
    }

    private const UNCHANGED = "\0unchanged";

    /** @return array{0:bool,1:mixed} [ok, valor normalizado ou UNCHANGED] */
    private function ok(mixed $value = self::UNCHANGED): array
    {
        return [true, $value];
    }

    /** @return array{0:bool,1:mixed} */
    private function check(string $rule, string $arg, string $field, mixed $value): array
    {
        $str = is_scalar($value) ? (string) $value : '';
        switch ($rule) {
            case 'email':
                if (!filter_var($str, FILTER_VALIDATE_EMAIL) || mb_strlen($str) > 190) {
                    return $this->fail($field, 'Informe um e-mail válido.');
                }
                return $this->ok(mb_strtolower($str));
            case 'min':
                if (is_numeric($value) && !is_string($value)) {
                    return $value >= (float) $arg ? $this->ok() : $this->fail($field, "O campo :campo deve ser no mínimo {$arg}.");
                }
                return mb_strlen($str) >= (int) $arg ? $this->ok() : $this->fail($field, "O campo :campo deve ter pelo menos {$arg} caracteres.");
            case 'max':
                if (is_numeric($value) && !is_string($value)) {
                    return $value <= (float) $arg ? $this->ok() : $this->fail($field, "O campo :campo deve ser no máximo {$arg}.");
                }
                return mb_strlen($str) <= (int) $arg ? $this->ok() : $this->fail($field, "O campo :campo deve ter no máximo {$arg} caracteres.");
            case 'between':
                [$a, $b] = array_pad(explode(',', $arg), 2, '0');
                if (!is_numeric($str)) {
                    return $this->fail($field, 'O campo :campo deve ser numérico.');
                }
                return ((float) $str >= (float) $a && (float) $str <= (float) $b) ? $this->ok() : $this->fail($field, "O campo :campo deve estar entre {$a} e {$b}.");
            case 'integer':
                if (filter_var($str, FILTER_VALIDATE_INT) === false) {
                    return $this->fail($field, 'O campo :campo deve ser um número inteiro.');
                }
                return $this->ok((int) $str);
            case 'digits':
                return preg_match('/^\d{' . (int) $arg . '}$/', $str) === 1 ? $this->ok() : $this->fail($field, "O campo :campo deve ter {$arg} dígitos.");
            case 'numeric':
                if (!is_numeric($str)) {
                    return $this->fail($field, 'O campo :campo deve ser numérico.');
                }
                return $this->ok($str + 0);
            case 'money':
                $money = self::parseMoney($str);
                if ($money === null) {
                    return $this->fail($field, 'Informe um valor válido (ex.: 1.234,56).');
                }
                return $this->ok($money);
            case 'boolean':
                if (in_array($value, [true, 1, '1', 'true', 'on', 'sim'], true)) {
                    return $this->ok(true);
                }
                if (in_array($value, [false, 0, '0', 'false', 'off', 'nao', 'não', ''], true)) {
                    return $this->ok(false);
                }
                return $this->fail($field, 'O campo :campo deve ser sim ou não.');
            case 'accepted':
                return in_array($value, [true, 1, '1', 'true', 'on', 'sim'], true) ? $this->ok() : $this->fail($field, 'Você precisa aceitar :campo.');
            case 'in':
                return in_array($str, explode(',', $arg), true) ? $this->ok() : $this->fail($field, 'O valor de :campo não é uma opção válida.');
            case 'not_in':
                return !in_array($str, explode(',', $arg), true) ? $this->ok() : $this->fail($field, 'O valor de :campo não é permitido.');
            case 'regex':
                return preg_match($arg, $str) === 1 ? $this->ok() : $this->fail($field, 'O formato de :campo é inválido.');
            case 'alpha_dash':
                return preg_match('/^[\pL\pN _-]+$/u', $str) === 1 ? $this->ok() : $this->fail($field, 'O campo :campo só pode ter letras, números, espaços, hífen e sublinhado.');
            case 'confirmed':
                $confirmation = $this->data[$field . '_confirmation'] ?? null;
                return ($confirmation !== null && (string) $confirmation === $str) ? $this->ok() : $this->fail($field, 'A confirmação de :campo não confere.');
            case 'same':
                return ((string) ($this->data[$arg] ?? '') === $str) ? $this->ok() : $this->fail($field, 'O campo :campo deve ser igual ao campo ' . $this->label($arg) . '.');
            case 'different':
                return ((string) ($this->data[$arg] ?? '') !== $str) ? $this->ok() : $this->fail($field, 'O campo :campo deve ser diferente do campo ' . $this->label($arg) . '.');
            case 'date':
                $d = DateTime::createFromFormat('!Y-m-d', $str);
                return ($d !== false && $d->format('Y-m-d') === $str) ? $this->ok() : $this->fail($field, 'Informe uma data válida (aaaa-mm-dd).');
            case 'date_br':
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
                    $d = DateTime::createFromFormat('!Y-m-d', $str);
                    return ($d !== false && $d->format('Y-m-d') === $str) ? $this->ok($str) : $this->fail($field, 'Informe uma data válida (dd/mm/aaaa).');
                }
                $d = DateTime::createFromFormat('!d/m/Y', $str);
                if ($d === false || $d->format('d/m/Y') !== $str) {
                    return $this->fail($field, 'Informe uma data válida (dd/mm/aaaa).');
                }
                return $this->ok($d->format('Y-m-d'));
            case 'datetime':
                $d = DateTime::createFromFormat('Y-m-d H:i', $str) ?: DateTime::createFromFormat('Y-m-d\TH:i', $str);
                return $d !== false ? $this->ok() : $this->fail($field, 'Informe data e hora válidas.');
            case 'time':
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $str) === 1 ? $this->ok() : $this->fail($field, 'Informe uma hora válida (hh:mm).');
            case 'color':
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $str) !== 1) {
                    return $this->fail($field, 'Informe uma cor no formato #rrggbb.');
                }
                return $this->ok(strtolower($str));
            case 'url':
                return filter_var($str, FILTER_VALIDATE_URL) !== false ? $this->ok() : $this->fail($field, 'Informe uma URL válida.');
            case 'array':
                return is_array($value) ? $this->ok() : $this->fail($field, 'O campo :campo deve ser uma lista.');
            case 'json':
                json_decode($str);
                return json_last_error() === JSON_ERROR_NONE ? $this->ok() : $this->fail($field, 'O campo :campo deve ser um JSON válido.');
            case 'timezone':
                return in_array($str, timezone_identifiers_list(), true) ? $this->ok() : $this->fail($field, 'Fuso horário inválido.');
            case 'cpf':
                $digits = preg_replace('/\D/', '', $str) ?? '';
                return self::isValidCpf($digits) ? $this->ok($digits) : $this->fail($field, 'Informe um CPF válido.');
            case 'password':
                $min = (int) Config::get('security.password_min_length', 10);
                if (mb_strlen($str) < $min) {
                    return $this->fail($field, "A senha deve ter pelo menos {$min} caracteres.");
                }
                if (!preg_match('/\pL/u', $str) || !preg_match('/\d/', $str)) {
                    return $this->fail($field, 'A senha deve misturar letras e números.');
                }
                if (self::isCommonPassword($str)) {
                    return $this->fail($field, 'Essa senha é muito comum e aparece em vazamentos. Escolha outra.');
                }
                return $this->ok();
            case 'unique':
                [$table, $column, $ignoreId, $idColumn] = array_pad(explode(',', $arg), 4, '');
                $column = $column !== '' ? $column : $field;
                $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?";
                $params = [$str];
                if ($ignoreId !== '') {
                    $sql .= ' AND `' . ($idColumn !== '' ? $idColumn : 'id') . '` <> ?';
                    $params[] = (int) $ignoreId;
                }
                return ((int) Database::scalar($sql, $params) === 0) ? $this->ok() : $this->fail($field, 'Este valor de :campo já está em uso.');
            case 'exists':
                [$table, $column] = array_pad(explode(',', $arg), 2, '');
                $column = $column !== '' ? $column : 'id';
                return ((int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?", [$str]) > 0)
                    ? $this->ok() : $this->fail($field, 'O valor de :campo não existe.');
            default:
                throw new \InvalidArgumentException("Regra de validação desconhecida: {$rule}");
        }
    }

    /** @return array{0:bool,1:mixed} */
    private function fail(string $field, string $message): array
    {
        $this->addError($field, $message);
        return [false, null];
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = str_replace(':campo', $this->label($field), $message);
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? str_replace('_', ' ', $field);
    }

    /** "1.234,56" | "1234,56" | "1234.56" | "R$ 1.234,56" → "1234.56" (string decimal segura para DECIMAL). */
    public static function parseMoney(string $input): ?string
    {
        $s = trim(str_ireplace(['R$', ' ', "\u{a0}"], '', $input));
        if ($s === '') {
            return null;
        }
        $negative = str_starts_with($s, '-');
        $s = ltrim($s, '-');
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
            return null;
        }
        $value = number_format((float) $s, 2, '.', '');
        if ((float) $value > 9999999999.99) {
            return null;
        }
        return ($negative ? '-' : '') . $value;
    }

    public static function isValidCpf(string $digits): bool
    {
        if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $digits[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $digits[$t] !== $digit) {
                return false;
            }
        }
        return true;
    }

    /** Lista embutida das senhas mais comuns em vazamentos (verificação local, sem serviço externo). */
    public static function isCommonPassword(string $password): bool
    {
        static $list = null;
        if ($list === null) {
            $file = (string) Config::get('paths.app') . '/Data/common_passwords.txt';
            $list = is_file($file) ? array_flip(array_map('trim', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])) : [];
        }
        $lower = mb_strtolower($password);
        // Também bloqueia variações óbvias: senha + dígitos no fim
        $stripped = preg_replace('/[\d!@#$%*.\-_]+$/', '', $lower) ?? $lower;
        return isset($list[$lower]) || ($stripped !== '' && isset($list[$stripped]));
    }
}
