<?php
// app/Services/TransactionService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Models\Transaction;
use App\Models\TransactionTemplate;
use DateTimeImmutable;
use RuntimeException;

/**
 * Regras dos lançamentos: criação (simples, parcelada, transferência), edição, status, lixeira e lote.
 */
final class TransactionService
{
    /**
     * Cria um lançamento (ou N parcelas). Devolve os ids criados.
     * @param array<string,mixed> $data campos já validados
     * @param array<string,mixed>|null $file item de $_FILES do comprovante
     * @return list<int>
     */
    public static function create(array $data, ?array $file = null): array
    {
        $householdId = (int) Auth::householdId();
        $userId = (int) Auth::id();
        $installments = max(1, (int) ($data['installments'] ?? 1));
        $model = new Transaction();
        $attachment = $file !== null ? AttachmentService::store($file, $householdId, $userId) : null;
        $group = $installments > 1 ? self::uuid() : null;
        $base = new DateTimeImmutable((string) $data['date']);
        $amount = (float) $data['amount'];
        $ids = [];

        Database::transaction(static function () use ($model, $data, $installments, $attachment, $group, $base, $amount, $userId, $householdId, &$ids): void {
            // Parcelas: divide o valor; a última absorve a diferença de arredondamento
            $per = round($amount / $installments, 2);
            for ($i = 1; $i <= $installments; $i++) {
                $value = $i === $installments ? round($amount - $per * ($installments - 1), 2) : $per;
                $date = $installments > 1 ? self::addMonths($base, $i - 1) : $base;
                $row = [
                    'account_id'          => (int) $data['account_id'],
                    'category_id'         => $data['type'] === 'transfer' ? null : ($data['category_id'] ?? null),
                    'responsible_user_id' => $data['responsible_user_id'] ?? null,
                    'created_by'          => $userId,
                    'type'                => $data['type'],
                    'amount'              => number_format($value, 2, '.', ''),
                    'date'                => $date->format('Y-m-d'),
                    'paid_at'             => ($data['status'] ?? 'paid') === 'paid' ? $date->format('Y-m-d') : null,
                    'description'         => $installments > 1 ? sprintf('%s (%d/%d)', $data['description'], $i, $installments) : $data['description'],
                    'notes'               => $data['notes'] ?? null,
                    'tags'                => $data['tags'] ?? null,
                    'status'              => $installments > 1 && $i > 1 ? 'pending' : ($data['status'] ?? 'paid'),
                    'installment_no'      => $installments > 1 ? $i : null,
                    'installment_total'   => $installments > 1 ? $installments : null,
                    'installment_group'   => $group,
                    'transfer_account_id' => $data['type'] === 'transfer' ? (int) $data['transfer_account_id'] : null,
                    'auto_debit'          => (int) ($data['auto_debit'] ?? 0),
                    'is_private'          => (int) ($data['is_private'] ?? 0),
                    'import_hash'         => Transaction::importHash($date->format('Y-m-d'), (string) $value, (string) $data['description'], (string) $data['type']),
                ];
                if ($attachment !== null && $i === 1) {
                    $row['attachment_path'] = $attachment['path'];
                    $row['attachment_name'] = $attachment['name'];
                    $row['attachment_mime'] = $attachment['mime'];
                }
                $ids[] = $model->create($row);
            }
        });
        foreach ($ids as $id) {
            AuditService::log('transaction.create', 'transaction', $id, null, ['type' => $data['type'], 'amount' => $amount, 'description' => $data['description']]);
        }
        if (!empty($data['category_id']) && $data['type'] !== 'transfer') {
            CategoryService::learn($householdId, (string) $data['description'], (int) $data['category_id']);
        }
        return $ids;
    }

    /** @param array<string,mixed> $data
     *  @param array<string,mixed>|null $file */
    public static function update(int $id, array $data, ?array $file = null, bool $removeAttachment = false): void
    {
        $model = new Transaction();
        $tx = $model->findOrFail($id);
        if (!TransactionPolicy::canEdit($tx)) {
            throw new HttpException(403, 'Você não pode editar este lançamento.');
        }
        $row = [
            'account_id'          => (int) $data['account_id'],
            'category_id'         => $data['type'] === 'transfer' ? null : ($data['category_id'] ?? null),
            'responsible_user_id' => $data['responsible_user_id'] ?? null,
            'type'                => $data['type'],
            'amount'              => number_format((float) $data['amount'], 2, '.', ''),
            'date'                => $data['date'],
            'description'         => $data['description'],
            'notes'               => $data['notes'] ?? null,
            'tags'                => $data['tags'] ?? null,
            'status'              => $data['status'] ?? 'paid',
            'transfer_account_id' => $data['type'] === 'transfer' ? (int) $data['transfer_account_id'] : null,
            'auto_debit'          => (int) ($data['auto_debit'] ?? 0),
            'is_private'          => (int) ($data['is_private'] ?? 0),
            'import_hash'         => Transaction::importHash((string) $data['date'], (string) $data['amount'], (string) $data['description'], (string) $data['type']),
        ];
        $row['paid_at'] = $row['status'] === 'paid' ? ($tx['paid_at'] ?? $data['date']) : null;
        if ($removeAttachment || $file !== null) {
            AttachmentService::delete($tx['attachment_path'] ?? null);
            $row['attachment_path'] = null;
            $row['attachment_name'] = null;
            $row['attachment_mime'] = null;
        }
        if ($file !== null) {
            $attachment = AttachmentService::store($file, (int) Auth::householdId(), (int) Auth::id());
            $row['attachment_path'] = $attachment['path'];
            $row['attachment_name'] = $attachment['name'];
            $row['attachment_mime'] = $attachment['mime'];
        }
        $model->update($id, $row);
        AuditService::log('transaction.update', 'transaction', $id, self::auditFields($tx), self::auditFields($row));
        if (!empty($row['category_id']) && $data['type'] !== 'transfer' && (int) ($tx['category_id'] ?? 0) !== (int) $row['category_id']) {
            CategoryService::learn((int) Auth::householdId(), (string) $data['description'], (int) $row['category_id']);
        }
    }

    public static function setStatus(int $id, string $status): void
    {
        $model = new Transaction();
        $tx = $model->findOrFail($id);
        if (!TransactionPolicy::canEdit($tx)) {
            throw new HttpException(403, 'Você não pode alterar este lançamento.');
        }
        $model->update($id, ['status' => $status, 'paid_at' => $status === 'paid' ? gmdate('Y-m-d') : null]);
        AuditService::log('transaction.status', 'transaction', $id, ['status' => $tx['status']], ['status' => $status]);
    }

    public static function trash(int $id): void
    {
        $model = new Transaction();
        $tx = $model->findOrFail($id);
        if (!TransactionPolicy::canEdit($tx)) {
            throw new HttpException(403, 'Você não pode excluir este lançamento.');
        }
        $model->delete($id, (int) Auth::id());
        AuditService::log('transaction.trash', 'transaction', $id, self::auditFields($tx), null);
    }

    public static function restore(int $id): void
    {
        $model = (new Transaction())->withTrashed();
        $tx = $model->findOrFail($id);
        if (!TransactionPolicy::canEdit($tx)) {
            throw new HttpException(403);
        }
        (new Transaction())->restore($id);
        AuditService::log('transaction.restore', 'transaction', $id);
    }

    public static function destroy(int $id): void
    {
        $model = (new Transaction())->withTrashed();
        $tx = $model->findOrFail($id);
        if (!TransactionPolicy::canEdit($tx) || $tx['deleted_at'] === null) {
            throw new HttpException(403, 'Só lançamentos na lixeira podem ser excluídos definitivamente.');
        }
        AttachmentService::delete($tx['attachment_path'] ?? null);
        (new Transaction())->forceDelete($id);
        AuditService::log('transaction.destroy', 'transaction', $id, self::auditFields($tx), null);
    }

    /**
     * Edição em lote. $action: category | status | responsible | trash | private | public. Devolve quantos foram alterados.
     * @param list<int> $ids
     */
    public static function bulk(array $ids, string $action, mixed $value = null): int
    {
        $count = 0;
        foreach ($ids as $id) {
            try {
                switch ($action) {
                    case 'trash':
                        self::trash($id);
                        break;
                    case 'status':
                        self::setStatus($id, (string) $value);
                        break;
                    default:
                        $model = new Transaction();
                        $tx = $model->findOrFail($id);
                        if (!TransactionPolicy::canEdit($tx)) {
                            continue 2;
                        }
                        $fields = match ($action) {
                            'category'    => $tx['type'] === 'transfer' ? [] : ['category_id' => $value !== null ? (int) $value : null],
                            'responsible' => ['responsible_user_id' => $value !== null && $value !== '' ? (int) $value : null],
                            'private'     => ['is_private' => 1],
                            'public'      => ['is_private' => 0],
                            default       => [],
                        };
                        if ($fields === []) {
                            continue 2;
                        }
                        $model->update($id, $fields);
                        AuditService::log('transaction.update', 'transaction', $id, array_intersect_key($tx, $fields), $fields);
                        if ($action === 'category' && $value !== null) {
                            CategoryService::learn((int) Auth::householdId(), (string) $tx['description'], (int) $value);
                        }
                }
                $count++;
            } catch (HttpException) {
                // sem permissão ou inexistente: pula
            }
        }
        return $count;
    }

    /** Guarda um lançamento como modelo favorito do usuário. */
    public static function saveAsTemplate(int $transactionId, string $name): int
    {
        $tx = (new Transaction())->findOrFail($transactionId);
        if (TransactionPolicy::isPrivateForMe($tx)) {
            throw new HttpException(403);
        }
        $id = (new TransactionTemplate())->create([
            'user_id'             => (int) Auth::id(),
            'name'                => $name,
            'type'                => $tx['type'],
            'amount'              => $tx['amount'],
            'category_id'         => $tx['category_id'],
            'account_id'          => $tx['account_id'],
            'responsible_user_id' => $tx['responsible_user_id'],
            'description'         => preg_replace('/ \(\d+\/\d+\)$/', '', (string) $tx['description']),
            'tags'                => $tx['tags'],
        ]);
        AuditService::log('template.create', 'transaction_template', $id, null, ['name' => $name]);
        return $id;
    }

    /** Último lançamento do usuário (para "repetir último"). */
    /** @return array<string,mixed>|null */
    public static function lastOfUser(): ?array
    {
        return (new Transaction())->query()->where('created_by', (int) Auth::id())->orderBy('id', 'DESC')->first();
    }

    private static function addMonths(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        // Evita o "31 de janeiro + 1 mês = 3 de março": fixa no último dia do mês de destino
        $day = (int) $date->format('j');
        $first = $date->modify('first day of this month')->modify("+{$months} months");
        $last = (int) $first->format('t');
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, $last));
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** @param array<string,mixed> $tx
     *  @return array<string,mixed> */
    private static function auditFields(array $tx): array
    {
        return array_intersect_key($tx, array_flip(['account_id', 'category_id', 'responsible_user_id', 'type', 'amount', 'date', 'description', 'status', 'is_private', 'transfer_account_id']));
    }
}
