<?php
// app/helpers.php
// Funções globais curtas usadas nas views e controllers. Todas sem estado próprio.
declare(strict_types=1);

use App\Core\App;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;

/** Escape de saída HTML. Use em TODA impressão de dado dinâmico. */
function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** URL de rota nomeada (inclui subpasta). */
/** @param array<string,int|string> $params
 *  @param array<string,mixed> $query */
function route(string $name, array $params = [], array $query = []): string
{
    return App::router()->url($name, $params, $query);
}

function route_exists(string $name): bool
{
    return App::router()->has($name);
}

/** URL relativa dentro do app: url('/lancamentos') → "/cofre/lancamentos". */
function url(string $path = ''): string
{
    $base = (string) Config::get('app.base_path', '');
    $path = '/' . ltrim($path, '/');
    return ($base . $path) === '' ? '/' : $base . $path;
}

/** URL absoluta (para e-mails e manifest). */
function absolute_url(string $path = ''): string
{
    return rtrim((string) Config::get('app.url', ''), '/') . '/' . ltrim($path, '/');
}

/** Asset com cache-busting pelo mtime do arquivo. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = (string) Config::get('paths.public') . '/' . $path;
    $version = is_file($file) ? (string) filemtime($file) : (string) Config::get('app.version');
    return url('/' . $path) . '?v=' . $version;
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return Csrf::field();
}

/** Campo oculto para PUT/PATCH/DELETE em formulários HTML. */
function method_field(string $method): string
{
    return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
}

function nonce(): string
{
    return App::nonce();
}

/** Valor antigo de formulário após erro de validação. */
function old(string $key, mixed $default = ''): mixed
{
    static $old = null;
    if ($old === null) {
        $old = Session::pullOldInput();
    }
    return $old[$key] ?? $default;
}

/** Erros de validação do campo (lista) ou de todos (array). */
/** @return list<string>|array<string,list<string>> */
function errors(?string $field = null): array
{
    static $errors = null;
    if ($errors === null) {
        $errors = Session::pullErrors();
    }
    if ($field === null) {
        return $errors;
    }
    return $errors[$field] ?? [];
}

function has_error(string $field): bool
{
    return errors($field) !== [];
}

function auth_user(): ?array
{
    return Auth::user();
}

function is_route(string ...$names): bool
{
    $current = App::currentRouteName();
    if ($current === null) {
        return false;
    }
    foreach ($names as $name) {
        if ($name === $current || (str_ends_with($name, '*') && str_starts_with($current, rtrim($name, '*')))) {
            return true;
        }
    }
    return false;
}

// --- Formatação pt-BR ---

/** R$ 1.234,56 (negativo: -R$ 1.234,56). */
function money(mixed $value, bool $withSymbol = true, string $currency = 'BRL'): string
{
    $number = (float) ($value ?? 0);
    $formatted = number_format(abs($number), 2, ',', '.');
    $symbol = match ($currency) { 'USD' => 'US$', 'EUR' => '€', default => 'R$' };
    $prefix = $withSymbol ? $symbol . "\u{a0}" : '';
    return ($number < 0 ? '-' : '') . $prefix . $formatted;
}

/** Fuso do usuário logado (ou padrão do app). */
function user_timezone(): DateTimeZone
{
    $user = Auth::user();
    $tz = (string) ($user['timezone'] ?? Config::get('app.timezone', 'America/Sao_Paulo'));
    try {
        return new DateTimeZone($tz);
    } catch (Throwable) {
        return new DateTimeZone('America/Sao_Paulo');
    }
}

/** Converte data/hora UTC (do banco) para o fuso do usuário. */
function to_user_time(DateTimeInterface|string|null $value): ?DateTimeImmutable
{
    if ($value === null || $value === '') {
        return null;
    }
    try {
        $dt = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $dt->setTimezone(user_timezone());
    } catch (Throwable) {
        return null;
    }
}

/** dd/mm/aaaa a partir de "Y-m-d" (datas de calendário, sem fuso). */
function date_br(?string $date): string
{
    if ($date === null || $date === '') {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
    return $d === false ? '' : $d->format('d/m/Y');
}

/** dd/mm/aaaa hh:mm no fuso do usuário a partir de DATETIME UTC. */
function datetime_br(DateTimeInterface|string|null $value, string $format = 'd/m/Y H:i'): string
{
    $dt = to_user_time($value);
    return $dt === null ? '' : $dt->format($format);
}

/** Nome do mês em pt-BR (1..12). */
function month_name(int $month, bool $short = false): string
{
    $names = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $name = $names[$month] ?? '';
    return $short ? mb_substr($name, 0, 3) : $name;
}

/** "há 5 minutos", "há 2 dias" — para listas de atividade. */
function time_ago(DateTimeInterface|string|null $value): string
{
    $dt = to_user_time($value);
    if ($dt === null) {
        return '';
    }
    $diff = time() - $dt->getTimestamp();
    if ($diff < 60) {
        return 'agora mesmo';
    }
    $units = [[31536000, 'ano', 'anos'], [2592000, 'mês', 'meses'], [86400, 'dia', 'dias'], [3600, 'hora', 'horas'], [60, 'minuto', 'minutos']];
    foreach ($units as [$seconds, $singular, $plural]) {
        if ($diff >= $seconds) {
            $n = intdiv($diff, $seconds);
            return 'há ' . $n . ' ' . ($n === 1 ? $singular : $plural);
        }
    }
    return 'agora mesmo';
}

/** Iniciais para avatar (ex.: "Flávio Spina" → "FS"). */
function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/** Primeira mensagem de erro do campo (ou vazio). */
function error_text(string $field): string
{
    $list = errors($field);
    return $list[0] ?? '';
}

/** Classe "is-invalid" quando o campo tem erro. */
function invalid_class(string $field): string
{
    return has_error($field) ? ' is-invalid' : '';
}

/** Bloco <div class="invalid-feedback"> com a primeira mensagem do campo. */
function field_error(string $field): string
{
    $text = error_text($field);
    return $text === '' ? '' : '<div class="invalid-feedback d-block">' . e($text) . '</div>';
}

/** Rótulo em pt-BR do papel no lar. */
function role_label(?string $role): string
{
    return \App\Core\Auth::ROLE_LABELS[$role ?? ''] ?? (string) $role;
}
