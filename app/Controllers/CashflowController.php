<?php
// app/Controllers/CashflowController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Services\CashflowService;
use App\Services\RecurrenceService;

/** Previsão de caixa (/previsao): curva dia a dia dos próximos 60 dias, primeiro dia negativo e sobra segura. */
final class CashflowController extends Controller
{
    public function index(): Response
    {
        $householdId = (int) Auth::householdId();
        $today = new \DateTimeImmutable('today', user_timezone());
        RecurrenceService::generate($householdId, $today);
        $days = max(14, min(120, (int) $this->request->query('dias', 60)));
        $forecast = CashflowService::forecast($householdId, $today, $days, Auth::id());
        return $this->view('cashflow/index', [
            'title'    => 'Previsão de caixa',
            'forecast' => $forecast,
            'days'     => $days,
            'today'    => $today->format('Y-m-d'),
        ]);
    }
}
