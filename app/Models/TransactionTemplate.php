<?php
// app/Models/TransactionTemplate.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Modelos favoritos de lançamento (por usuário, dentro do lar). */
final class TransactionTemplate extends Model
{
    protected string $table = 'transaction_templates';
    protected bool $softDeletes = true;
    protected array $fillable = ['user_id', 'name', 'type', 'amount', 'category_id', 'account_id', 'responsible_user_id', 'description', 'tags', 'sort_order'];
    protected array $json = ['tags'];
}
