<?php
// tests/run.php — executor de testes sem dependências: php tests/run.php [filtro]
// Cada arquivo tests/*Test.php declara funções test_*() que usam os helpers assert_* abaixo.
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('NC_TESTING', true);
putenv('NC_TESTING=1');

require APP_ROOT . '/app/bootstrap.php';

final class TestFailure extends RuntimeException
{
}

function assert_true(mixed $value, string $message = ''): void
{
    if ($value !== true) {
        throw new TestFailure($message !== '' ? $message : 'Esperado true, obtido ' . var_export($value, true));
    }
}

function assert_false(mixed $value, string $message = ''): void
{
    if ($value !== false) {
        throw new TestFailure($message !== '' ? $message : 'Esperado false, obtido ' . var_export($value, true));
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new TestFailure(($message !== '' ? $message . ': ' : '') . 'esperado ' . var_export($expected, true) . ', obtido ' . var_export($actual, true));
    }
}

function assert_null(mixed $actual, string $message = ''): void
{
    assert_same(null, $actual, $message);
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new TestFailure(($message !== '' ? $message . ': ' : '') . "'{$needle}' não encontrado em '" . mb_substr($haystack, 0, 200) . "'");
    }
}

/** @param class-string<Throwable> $class */
function assert_throws(string $class, callable $fn, string $message = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new TestFailure(($message !== '' ? $message . ': ' : '') . 'esperada exceção ' . $class . ', lançada ' . get_class($e) . ': ' . $e->getMessage());
    }
    throw new TestFailure(($message !== '' ? $message . ': ' : '') . 'esperada exceção ' . $class . ', nenhuma lançada');
}

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);
$passed = 0;
$failed = 0;
$failures = [];

foreach ($files as $file) {
    $before = get_defined_functions()['user'];
    require $file;
    $after = get_defined_functions()['user'];
    $tests = array_filter(array_diff($after, $before), static fn(string $f): bool => str_starts_with($f, 'test_'));
    foreach ($tests as $test) {
        if ($filter !== '' && stripos($test, $filter) === false) {
            continue;
        }
        try {
            $test();
            $passed++;
            echo "  ✔ {$test}\n";
        } catch (Throwable $e) {
            $failed++;
            $failures[] = [$test, $e];
            echo "  ✘ {$test}\n      " . get_class($e) . ': ' . $e->getMessage() . "\n";
        }
    }
}

echo "\n{$passed} passaram, {$failed} falharam.\n";
exit($failed === 0 ? 0 : 1);
