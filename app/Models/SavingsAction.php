<?php
// app/Models/SavingsAction.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Plano de ação de economia: itens com economia estimada e medida. */
final class SavingsAction extends Model
{
    protected string $table = 'savings_actions';
    protected bool $softDeletes = true;
    protected array $fillable = ['title', 'description', 'responsible_user_id', 'category_id', 'status', 'estimated_saving_month', 'baseline_amount', 'measured_saving_month', 'started_at', 'done_at', 'sort_order'];

    public const STATUSES = ['todo' => 'A fazer', 'doing' => 'Em andamento', 'done' => 'Feito'];
}
