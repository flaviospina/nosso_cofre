<?php
// app/Services/SavingsActionService.php
declare(strict_types=1);

namespace App\Services;

use App\Models\SavingsAction;
use DateTimeImmutable;

/**
 * Plano de ação de economia: economia estimada (informada) × realizada (média dos 3 meses anteriores ao início
 * menos o gasto do mês atual na categoria ligada).
 */
final class SavingsActionService
{
    /** @return array{items:list<array<string,mixed>>,estimated:float,measured:float,done:int,total:int} */
    public static function overview(int $householdId, DateTimeImmutable $today): array
    {
        $items = SavingsAction::forHousehold($householdId)->all([], 'sort_order', 'ASC');
        $monthStart = $today->format('Y-m-01');
        $monthEnd = $today->modify('last day of this month')->format('Y-m-d');
        $estimated = 0.0;
        $measured = 0.0;
        $done = 0;
        foreach ($items as &$a) {
            $a['baseline'] = $a['baseline_amount'] !== null ? (float) $a['baseline_amount'] : null;
            $a['current'] = null;
            $a['measured'] = null;
            if ($a['category_id'] !== null && $a['status'] !== 'todo') {
                $baseline = $a['baseline'] ?? BudgetService::averageMonthly($householdId, (int) $a['category_id'], $a['started_at'] ? (new DateTimeImmutable((string) $a['started_at']))->format('Y-m-01') : $monthStart);
                $current = BudgetService::spent($householdId, (int) $a['category_id'], $monthStart, $monthEnd);
                $a['baseline'] = round($baseline, 2);
                $a['current'] = round($current, 2);
                $a['measured'] = round($baseline - $current, 2);
                $measured += $a['measured'];
            }
            if ($a['status'] !== 'done') {
                $estimated += (float) $a['estimated_saving_month'];
            } else {
                $done++;
                $estimated += (float) $a['estimated_saving_month'];
            }
        }
        unset($a);
        return ['items' => $items, 'estimated' => round($estimated, 2), 'measured' => round($measured, 2), 'done' => $done, 'total' => count($items)];
    }

    /** Muda a situação; ao iniciar, congela a linha de base (média dos 3 meses anteriores). */
    public static function setStatus(int $householdId, int $id, string $status, DateTimeImmutable $today): void
    {
        $model = SavingsAction::forHousehold($householdId);
        $action = $model->findOrFail($id);
        $fields = ['status' => $status];
        if ($status !== 'todo' && empty($action['started_at'])) {
            $fields['started_at'] = $today->format('Y-m-d');
            if ($action['category_id'] !== null) {
                $fields['baseline_amount'] = number_format(BudgetService::averageMonthly($householdId, (int) $action['category_id'], $today->format('Y-m-01')), 2, '.', '');
            }
        }
        if ($status === 'done') {
            $fields['done_at'] = $today->format('Y-m-d');
            if ($action['category_id'] !== null) {
                $baseline = (float) ($fields['baseline_amount'] ?? $action['baseline_amount'] ?? 0);
                $current = BudgetService::spent($householdId, (int) $action['category_id'], $today->format('Y-m-01'), $today->modify('last day of this month')->format('Y-m-d'));
                $fields['measured_saving_month'] = number_format($baseline - $current, 2, '.', '');
            }
        } else {
            $fields['done_at'] = null;
        }
        $model->update($id, $fields);
        AuditService::log('savings_action.status', 'savings_action', $id, ['status' => $action['status']], ['status' => $status]);
    }
}
