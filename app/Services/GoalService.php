<?php
// app/Services/GoalService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Goal;
use App\Models\GoalContribution;
use DateTimeImmutable;

/** Metas: progresso, sugestão de aporte mensal e registro de aportes. */
final class GoalService
{
    /** @return list<array<string,mixed>> */
    public static function withProgress(int $householdId, DateTimeImmutable $today, bool $includeClosed = true): array
    {
        $goals = Goal::forHousehold($householdId)->all([], 'deadline', 'ASC');
        $balances = [];
        foreach (AccountService::withBalances($householdId, false) as $a) {
            $balances[(int) $a['id']] = (float) $a['balance'];
        }
        $out = [];
        foreach ($goals as $g) {
            if (!$includeClosed && $g['status'] !== 'active') {
                continue;
            }
            $saved = $g['linked_account_id'] !== null && isset($balances[(int) $g['linked_account_id']]) ? max((float) $g['saved_amount'], $balances[(int) $g['linked_account_id']]) : (float) $g['saved_amount'];
            $target = (float) $g['target_amount'];
            $pct = $target > 0 ? min(100, (int) round($saved / $target * 100)) : 0;
            $monthsLeft = null;
            if (!empty($g['deadline'])) {
                $d = new DateTimeImmutable((string) $g['deadline']);
                $diff = $today->diff($d);
                $monthsLeft = $d < $today ? 0 : $diff->y * 12 + $diff->m + ($diff->d > 0 ? 1 : 0);
            }
            $gap = max(0.0, $target - $saved);
            $g['saved'] = round($saved, 2);
            $g['pct'] = $pct;
            $g['gap'] = round($gap, 2);
            $g['months_left'] = $monthsLeft;
            $g['suggested_monthly'] = $gap > 0 ? round($gap / max(1, $monthsLeft ?? 12), 2) : 0.0;
            $g['overdue'] = $monthsLeft === 0 && $gap > 0 && $g['status'] === 'active';
            $out[] = $g;
        }
        return $out;
    }

    /** Registra um aporte (negativo = retirada), atualiza o acumulado e conclui a meta ao atingir o alvo. */
    public static function contribute(int $householdId, int $goalId, float $amount, string $date, ?string $note, ?int $userId): array
    {
        $model = Goal::forHousehold($householdId);
        $goal = $model->findOrFail($goalId);
        GoalContribution::forHousehold($householdId)->create(['goal_id' => $goalId, 'user_id' => $userId, 'amount' => number_format($amount, 2, '.', ''), 'date' => $date, 'note' => $note, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $saved = max(0.0, (float) $goal['saved_amount'] + $amount);
        $fields = ['saved_amount' => number_format($saved, 2, '.', '')];
        $achieved = false;
        if ($saved >= (float) $goal['target_amount'] && $goal['status'] === 'active') {
            $fields['status'] = 'done';
            $fields['achieved_at'] = gmdate('Y-m-d H:i:s');
            $achieved = true;
        }
        $model->update($goalId, $fields);
        AuditService::log('goal.contribution', 'goal', $goalId, null, ['amount' => $amount, 'saved' => $saved]);
        if ($achieved) {
            AuditService::log('goal.achieved', 'goal', $goalId, null, ['target' => $goal['target_amount']]);
        }
        return ['saved' => $saved, 'achieved' => $achieved];
    }

    /** @return list<array<string,mixed>> */
    public static function contributions(int $householdId, int $goalId, int $limit = 20): array
    {
        return Database::select('SELECT c.*, u.name AS user_name FROM goal_contributions c LEFT JOIN users u ON u.id = c.user_id WHERE c.household_id = ? AND c.goal_id = ? ORDER BY c.date DESC, c.id DESC LIMIT ' . (int) $limit, [$householdId, $goalId]);
    }
}
