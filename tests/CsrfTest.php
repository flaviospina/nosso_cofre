<?php
// tests/CsrfTest.php — token CSRF e o middleware global que o exige em métodos de escrita
declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Request;
use App\Core\Router;

function test_csrf_token_is_stable_and_validates(): void
{
    $_SESSION = [];
    $token = Csrf::token();
    assert_same(64, strlen($token));
    assert_same($token, Csrf::token(), 'mesmo token na mesma sessão');
    assert_true(Csrf::validate($token));
    assert_false(Csrf::validate('x'));
    assert_false(Csrf::validate(null));
    Csrf::rotate();
    assert_false(Csrf::validate($token), 'token antigo inválido após rotação');
}

function test_csrf_middleware_blocks_post_without_token_and_accepts_header(): void
{
    $_SESSION = [];
    Config::set('app.base_path', '/cofre');
    $router = new Router();
    $router->setMiddlewareAliases(['csrf' => CsrfMiddleware::class]);
    $router->setGlobalMiddleware(['csrf']);
    $router->post('/salvar', static fn() => 'salvo', 'save');
    $router->get('/ver', static fn() => 'visto', 'view');

    $base = ['REQUEST_URI' => '/cofre/salvar', 'SCRIPT_NAME' => '/cofre/public/index.php'];

    // GET não exige token
    assert_same('visto', $router->dispatch(new Request([], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/cofre/ver'], []))->body());

    // POST sem token → 419
    try {
        $router->dispatch(new Request([], [], [], $base + ['REQUEST_METHOD' => 'POST'], []));
        throw new TestFailure('POST sem token deveria falhar');
    } catch (HttpException $e) {
        assert_same(419, $e->getStatus());
    }

    // POST com campo _token
    $ok = $router->dispatch(new Request([], ['_token' => Csrf::token()], [], $base + ['REQUEST_METHOD' => 'POST'], []));
    assert_same('salvo', $ok->body());

    // POST JSON com cabeçalho X-CSRF-Token
    $ok = $router->dispatch(new Request([], [], [], $base + ['REQUEST_METHOD' => 'POST', 'HTTP_X_CSRF_TOKEN' => Csrf::token(), 'CONTENT_TYPE' => 'application/json'], [], '{"a":1}'));
    assert_same('salvo', $ok->body());
}
