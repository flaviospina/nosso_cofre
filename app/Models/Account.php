<?php
// app/Models/Account.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Account extends Model
{
    protected string $table = 'accounts';
    protected bool $softDeletes = true;
    protected array $fillable = ['name', 'type', 'owner_user_id', 'institution', 'initial_balance', 'closing_day', 'due_day', 'limit_amount', 'color', 'icon', 'is_active', 'sort_order'];

    public const TYPES = ['checking' => 'Conta corrente', 'savings' => 'Poupança / reserva', 'credit_card' => 'Cartão de crédito', 'cash' => 'Dinheiro', 'investment' => 'Investimento'];
    public const ICONS = ['checking' => 'bank', 'savings' => 'safe2', 'credit_card' => 'credit-card', 'cash' => 'cash', 'investment' => 'graph-up-arrow'];
}
