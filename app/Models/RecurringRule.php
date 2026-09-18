<?php
// app/Models/RecurringRule.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Regras de recorrência (contas fixas, assinaturas, receitas periódicas e eventos previstos como a PLR). */
final class RecurringRule extends Model
{
    protected string $table = 'recurring_rules';
    protected bool $softDeletes = true;
    protected array $fillable = [
        'description', 'kind', 'category_id', 'account_id', 'responsible_user_id', 'expected_amount', 'expected_amount_source',
        'frequency', 'interval_count', 'day_of_month', 'day_of_week', 'month_of_year', 'start_date', 'end_date', 'next_run_date',
        'generate_days_ahead', 'auto_debit', 'is_subscription', 'is_major_event', 'notify_days_before', 'last_usage_confirmed_at', 'is_active',
    ];

    public const FREQUENCIES = ['weekly' => 'Semanal', 'monthly' => 'Mensal', 'yearly' => 'Anual', 'custom' => 'A cada N dias'];
    public const SOURCES = ['fixed' => 'Valor fixo', 'average' => 'Média dos últimos 3 meses'];
}
