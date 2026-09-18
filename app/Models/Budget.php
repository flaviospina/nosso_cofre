<?php
// app/Models/Budget.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Orçamento mensal por categoria (e opcionalmente por membro). */
final class Budget extends Model
{
    protected string $table = 'budgets';
    protected array $fillable = ['category_id', 'user_id', 'period_month', 'limit_amount', 'alert_thresholds'];
    protected array $json = ['alert_thresholds'];
}
