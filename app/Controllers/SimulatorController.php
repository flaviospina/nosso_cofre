<?php
// app/Controllers/SimulatorController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Core\Validator;
use App\Services\SimulatorService;

/** Simulador "e se" (/simulador): cortes por categoria e cancelamento de assinaturas. */
final class SimulatorController extends Controller
{
    public function index(): Response
    {
        $householdId = (int) Auth::householdId();
        $today = new \DateTimeImmutable('today', user_timezone());
        $baseline = SimulatorService::baseline($householdId, $today);
        $cuts = [];
        foreach ((array) $this->request->query('corte', []) as $categoryId => $value) {
            $money = Validator::parseMoney((string) $value);
            if ($money !== null && (float) $money > 0) {
                $cuts[(int) $categoryId] = (float) $money;
            }
        }
        $cancel = array_values(array_map('intval', (array) $this->request->query('cancelar', [])));
        $result = ($cuts !== [] || $cancel !== []) ? SimulatorService::simulate($baseline, $cuts, $cancel) : null;
        return $this->view('simulator/index', [
            'title'    => 'Simulador "e se"',
            'baseline' => $baseline,
            'cuts'     => $cuts,
            'cancel'   => $cancel,
            'result'   => $result,
        ]);
    }
}
