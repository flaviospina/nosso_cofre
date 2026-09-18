<?php
// app/Models/GoalContribution.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Aportes (e retiradas) em metas. */
final class GoalContribution extends Model
{
    protected string $table = 'goal_contributions';
    protected bool $timestamps = false;
    protected array $fillable = ['goal_id', 'user_id', 'amount', 'date', 'note', 'transaction_id', 'created_at'];
}
