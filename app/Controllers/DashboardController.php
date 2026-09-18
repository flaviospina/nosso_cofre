<?php
// app/Controllers/DashboardController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Models\Category;
use App\Models\HouseholdMember;
use App\Services\AccountService;
use App\Services\InsightsService;
use App\Services\RecurrenceService;
use App\Services\TransactionPolicy;

/** Painel (/painel?mes=AAAA-MM&membro=id): os 11 indicadores com gráficos e filtros por mês e membro. */
final class DashboardController extends Controller
{
    public function index(): Response
    {
        $household = Auth::household();
        $householdId = (int) Auth::householdId();
        $today = new \DateTimeImmutable('today', user_timezone());
        $month = $this->month($today);
        $memberId = null;
        $members = (new HouseholdMember())->activeMembers();
        $wanted = (int) $this->request->query('membro', 0);
        foreach ($members as $m) {
            if ((int) $m['user_id'] === $wanted) {
                $memberId = $wanted;
            }
        }
        RecurrenceService::generate($householdId, $today);
        $data = InsightsService::dashboard($householdId, $month, $memberId, $today, (int) Auth::id());
        $monthDate = new \DateTimeImmutable($month);
        return $this->view('dashboard/index', [
            'title'      => 'Início',
            'household'  => $household,
            'members'    => $members,
            'memberId'   => $memberId,
            'accounts'   => AccountService::withBalances($householdId, true),
            'insights'   => $data,
            'month'      => $month,
            'monthLabel' => month_name((int) $monthDate->format('n')) . ' de ' . $monthDate->format('Y'),
            'prevMonth'  => $monthDate->modify('-1 month')->format('Y-m'),
            'nextMonth'  => $monthDate->modify('+1 month')->format('Y-m'),
            'isCurrent'  => $monthDate->format('Y-m') === $today->format('Y-m'),
            'today'      => $today->format('Y-m-d'),
            'stage'      => (string) ($household['settings']['onboarding'] ?? 'setup'),
            'canWrite'   => TransactionPolicy::canCreate(),
            'isFamily'   => Auth::isFamily(),
            'categories' => (new Category())->map(),
        ]);
    }

    private function month(\DateTimeImmutable $today): string
    {
        $value = (string) $this->request->query('mes', '');
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return $m[1] . '-' . $m[2] . '-01';
        }
        return $today->format('Y-m-01');
    }
}
