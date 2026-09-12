<?php
// app/Controllers/HomeController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;

final class HomeController extends Controller
{
    /** Página inicial: apresenta o app para visitantes; usuário logado vai para o painel quando ele existir. */
    public function index(): Response
    {
        if (Auth::check() && route_exists('dashboard')) {
            return $this->redirectRoute('dashboard');
        }
        return $this->view('home/index', ['title' => 'Bem-vindo']);
    }
}
