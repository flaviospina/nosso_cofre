<?php
// public/index.php — front controller: toda requisição dinâmica passa por aqui.
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Request;

App::handle(Request::fromGlobals())->send();
