<?php
// app/Models/Transaction.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Transaction extends Model
{
    protected string $table = 'transactions';
    protected bool $softDeletes = true;
    protected array $fillable = [
        'account_id', 'category_id', 'responsible_user_id', 'created_by', 'type', 'amount', 'date', 'paid_at', 'description', 'notes',
        'attachment_path', 'attachment_name', 'attachment_mime', 'tags', 'status', 'recurring_id', 'installment_no', 'installment_total',
        'installment_group', 'transfer_account_id', 'transfer_pair_id', 'auto_debit', 'is_private', 'import_batch_id', 'import_hash', 'deleted_at', 'deleted_by',
    ];
    protected array $encrypted = ['notes'];
    protected array $json = ['tags'];

    public const TYPES = ['expense' => 'Despesa', 'income' => 'Receita', 'transfer' => 'Transferência'];
    public const STATUSES = ['paid' => 'Pago', 'pending' => 'A pagar', 'scheduled' => 'Agendado'];

    /** Hash para detectar duplicados (importação e lançamento manual): data + valor + descrição normalizada. */
    public static function importHash(string $date, string $amount, string $description, string $type): string
    {
        $normalized = preg_replace('/\s+/', ' ', mb_strtolower(trim($description))) ?? '';
        return hash('sha256', $date . '|' . number_format((float) $amount, 2, '.', '') . '|' . $type . '|' . $normalized);
    }
}
