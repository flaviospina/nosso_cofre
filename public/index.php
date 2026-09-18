<?php
// public/index.php - front controller: toda requisicao dinamica passa por aqui.
// Dois layouts de publicacao sao suportados:
//   A) projeto inteiro em uma pasta (public/ e uma subpasta dele);
//   B) so o conteudo de public/ na pasta publica e o restante fora dela, apontado por app-root.php
//      (arquivo opcional ao lado deste, que devolve o caminho absoluto da pasta do projeto).
declare(strict_types=1);

define('PUBLIC_ROOT', __DIR__);

$appRoot = dirname(__DIR__);
if (is_file(__DIR__ . '/app-root.php')) {
    $configured = require __DIR__ . '/app-root.php';
    if (is_string($configured) && $configured !== '') {
        $appRoot = rtrim($configured, '/\\');
    }
}
if (!is_file($appRoot . '/app/bootstrap.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Nosso Cofre: pasta do projeto nao encontrada em {$appRoot}.\n";
    echo "Crie o arquivo app-root.php ao lado deste index.php devolvendo o caminho absoluto da pasta que contem app/ e storage/.\n";
    exit;
}
define('APP_ROOT', $appRoot);

require APP_ROOT . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Request;

App::handle(Request::fromGlobals())->send();
