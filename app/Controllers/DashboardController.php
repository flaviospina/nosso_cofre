<?php
// app/Controllers/DashboardController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Models\Account;
use App\Models\HouseholdMember;

/**
 * Painel inicial. Nesta fase mostra o lar, os membros, as contas e os próximos passos;
 * os indicadores financeiros entram na fase 6.
 */
final class DashboardController extends Controller
{
    public function index(): Response
    {
        $household = Auth::household();
        $members = (new HouseholdMember())->activeMembers();
        $accounts = (new Account())->all(['is_active' => 1], 'sort_order', 'ASC');
        $stage = (string) ($household['settings']['onboarding'] ?? 'setup');
        return $this->view('dashboard/index', [
            'title'     => 'Início',
            'household' => $household,
            'members'   => $members,
            'accounts'  => $accounts,
            'stage'     => $stage,
        ]);
    }
}
