<?php
// tests/RouterTest.php
declare(strict_types=1);

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Router;

function makeRequest(string $method, string $uri, array $post = [], array $server = []): Request
{
    return new Request([], $post, [], array_merge(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/cofre/public/index.php'], $server), []);
}

function test_router_matches_named_params_and_builds_url(): void
{
    $router = new Router();
    $router->get('/lancamentos/editar/{id:\d+}', static fn(Request $r, string $id) => "editar {$id}", 'tx.edit');
    $router->get('/relatorios/{year:\d{4}}/{month:\d{2}}', static fn(Request $r, string $y, string $m) => "{$y}-{$m}", 'reports.month');

    Config::set('app.base_path', '/cofre');
    $response = $router->dispatch(makeRequest('GET', '/cofre/lancamentos/editar/123'));
    assert_same('editar 123', $response->body());
    assert_same('/cofre/lancamentos/editar/9', $router->url('tx.edit', ['id' => 9]));
    assert_same('/cofre/relatorios/2026/09?membro=2', $router->url('reports.month', ['year' => 2026, 'month' => '09'], ['membro' => 2]));
}

function test_router_404_and_405(): void
{
    $router = new Router();
    $router->post('/salvar', static fn() => 'ok', 'save');
    Config::set('app.base_path', '/cofre');
    assert_throws(HttpException::class, static fn() => $router->dispatch(makeRequest('GET', '/cofre/nao-existe')));
    try {
        $router->dispatch(makeRequest('GET', '/cofre/salvar'));
    } catch (HttpException $e) {
        assert_same(405, $e->getStatus(), 'GET em rota só-POST deve dar 405');
    }
}

function test_router_group_prefix_and_method_override(): void
{
    $router = new Router();
    $router->group(['prefix' => '/familia'], static function (Router $r): void {
        $r->delete('/membros/{id:\d+}', static fn(Request $req, string $id) => "removido {$id}", 'family.members.remove');
    });
    Config::set('app.base_path', '/cofre');
    $response = $router->dispatch(makeRequest('POST', '/cofre/familia/membros/7', ['_method' => 'DELETE']));
    assert_same('removido 7', $response->body());
    assert_same('/cofre/familia/membros/7', $router->url('family.members.remove', ['id' => 7]));
}

function test_request_strips_base_path_and_public(): void
{
    Config::set('app.base_path', '/cofre');
    assert_same('/lancamentos', makeRequest('GET', '/cofre/lancamentos?x=1')->path());
    assert_same('/', makeRequest('GET', '/cofre/')->path());
    assert_same('/', makeRequest('GET', '/cofre')->path());
    Config::set('app.base_path', '');
    // Sem APP_URL: deduz pelo SCRIPT_NAME (/cofre/public/index.php → /cofre)
    assert_same('/saude', makeRequest('GET', '/cofre/saude')->path());
    Config::set('app.base_path', '/cofre');
}

function test_request_ip_ignores_forwarded_from_untrusted_proxy(): void
{
    Config::set('security.trusted_proxies', []);
    $req = makeRequest('GET', '/cofre/', [], ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '10.0.0.1']);
    assert_same('203.0.113.5', $req->ip());
    Config::set('security.trusted_proxies', ['203.0.113.5']);
    assert_same('10.0.0.1', $req->ip());
    Config::set('security.trusted_proxies', []);
}

function test_request_falls_back_to_script_name_when_app_url_mismatches(): void
{
    // .env ainda com /cofre, mas o app foi publicado em /nossocofre (layout B: index.php direto na pasta)
    Config::set('app.base_path', '/cofre');
    $req = new Request([], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/nossocofre/instalar', 'SCRIPT_NAME' => '/nossocofre/index.php'], []);
    assert_same('/instalar', $req->path());
    assert_true($req->appUrlMismatch());
    // Layout A (index.php em /nossocofre/public/) tambem
    $req = new Request([], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/nossocofre/', 'SCRIPT_NAME' => '/nossocofre/public/index.php'], []);
    assert_same('/', $req->path());
    Config::set('app.base_path', '/nossocofre');
    Config::set('app.url', 'https://itthrive.com.br/nossocofre');
    $req = new Request([], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/nossocofre/saude', 'SCRIPT_NAME' => '/nossocofre/index.php', 'HTTP_HOST' => 'itthrive.com.br'], []);
    assert_false($req->appUrlMismatch());
    assert_same('/saude', $req->path());
    Config::set('app.base_path', '/cofre');
}

function test_base_path_from_server_variants(): void
{
    // DOCUMENT_ROOT + SCRIPT_FILENAME (handler real)
    assert_same('/nossocofre', Request::basePathFromServer(['DOCUMENT_ROOT' => '/home1/u/public_html', 'SCRIPT_FILENAME' => '/home1/u/public_html/nossocofre/index.php', 'SCRIPT_NAME' => '/nossocofre/index.php']));
    assert_same('/nossocofre', Request::basePathFromServer(['DOCUMENT_ROOT' => '/home1/u/public_html', 'SCRIPT_FILENAME' => '/home1/u/public_html/nossocofre/public/index.php']));
    // Sem DOCUMENT_ROOT: sufixo do diretorio x URI
    assert_same('/nossocofre', Request::basePathFromServer(['SCRIPT_FILENAME' => '/var/www/html/nossocofre/index.php', 'REQUEST_URI' => '/nossocofre/instalar?x=1', 'SCRIPT_NAME' => '/var/www/html/nossocofre/index.php']));
    assert_same('', Request::basePathFromServer(['SCRIPT_FILENAME' => '/var/www/html/index.php', 'REQUEST_URI' => '/instalar', 'SCRIPT_NAME' => '/index.php']));
    // Raiz do dominio
    assert_same('', Request::basePathFromServer(['DOCUMENT_ROOT' => '/home1/u/public_html', 'SCRIPT_FILENAME' => '/home1/u/public_html/index.php', 'SCRIPT_NAME' => '/index.php']));
}
