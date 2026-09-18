<?php
// app/Models/Goal.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Metas de economia (reserva, viagem, quitar dívida…). */
final class Goal extends Model
{
    protected string $table = 'goals';
    protected bool $softDeletes = true;
    protected array $fillable = ['name', 'target_amount', 'saved_amount', 'deadline', 'linked_category_id', 'linked_account_id', 'user_id', 'status', 'achieved_at', 'color', 'icon'];

    public const STATUSES = ['active' => 'Em andamento', 'done' => 'Concluída', 'archived' => 'Arquivada'];
}
