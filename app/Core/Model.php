<?php
// app/Core/Model.php
declare(strict_types=1);

namespace App\Core;

use LogicException;

/**
 * Model base com isolamento por lar (household) e soft delete.
 *
 * Regra central de segurança (multi-tenant): todo model com $householdScoped = true injeta
 * "household_id = <lar do usuário logado>" em TODA consulta, inclusive find(). Um id vindo da URL
 * que pertença a outro lar simplesmente não é encontrado (404), o que elimina IDOR por construção.
 * Se não houver lar resolvido (usuário sem lar, cron sem contexto), o model lança exceção
 * em vez de devolver dados de todos os lares.
 */
abstract class Model
{
    protected string $table = '';
    protected string $primaryKey = 'id';
    protected bool $householdScoped = true;
    protected bool $softDeletes = false;
    protected bool $timestamps = true;
    /** Colunas que podem ser preenchidas via create()/update(). Vazio = nenhuma (proteção contra mass assignment). */
    /** @var list<string> */
    protected array $fillable = [];
    /** Colunas gravadas criptografadas (Crypto). */
    /** @var list<string> */
    protected array $encrypted = [];
    /** Colunas JSON (decodificadas na leitura, codificadas na gravação). */
    /** @var list<string> */
    protected array $json = [];

    protected ?int $householdId = null;
    private bool $withTrashed = false;

    public function __construct(?int $householdId = null)
    {
        $this->householdId = $householdId;
    }

    /** Instância presa a um lar específico (uso em cron/serviços fora de sessão). */
    public static function forHousehold(int $householdId): static
    {
        return new static($householdId);
    }

    public function table(): string
    {
        return $this->table;
    }

    protected function householdId(): int
    {
        $id = $this->householdId ?? Auth::householdId();
        if ($id === null || $id <= 0) {
            throw new LogicException(static::class . ': consulta sem lar (household) definido.');
        }
        return $id;
    }

    /** Inclui registros na lixeira nas próximas consultas desta instância. */
    public function withTrashed(): static
    {
        $this->withTrashed = true;
        return $this;
    }

    /** Ponto de extensão para models que precisam de escopo diferente (ex.: categorias globais). */
    protected function applyScope(Query $query): void
    {
        if ($this->householdScoped) {
            $query->where('household_id', $this->householdId());
        }
        if ($this->softDeletes && !$this->withTrashed) {
            $query->whereNull('deleted_at');
        }
    }

    public function query(): Query
    {
        $query = new Query($this->table);
        $this->applyScope($query);
        return $query;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->query()->where($this->primaryKey, $id)->first();
        return $row === null ? null : $this->castRow($row);
    }

    /** @return array<string,mixed> */
    public function findOrFail(int $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            throw new HttpException(404, 'Registro não encontrado.');
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $where
     * @return array<int,array<string,mixed>>
     */
    public function all(array $where = [], string $orderBy = 'id', string $direction = 'DESC', ?int $limit = null, int $offset = 0): array
    {
        $query = $this->query()->where($where)->orderBy($orderBy, $direction)->limit($limit, $offset);
        return array_map(fn(array $r): array => $this->castRow($r), $query->get());
    }

    /** @param array<string,mixed> $where */
    public function count(array $where = []): int
    {
        return $this->query()->where($where)->count();
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $data = $this->prepare($data);
        if ($this->householdScoped) {
            $data['household_id'] = $this->householdId();
        }
        if ($this->timestamps) {
            $data['created_at'] = gmdate('Y-m-d H:i:s');
            $data['updated_at'] = $data['created_at'];
        }
        return Database::insert($this->table, $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): bool
    {
        $data = $this->prepare($data);
        if ($data === []) {
            return false;
        }
        if ($this->timestamps) {
            $data['updated_at'] = gmdate('Y-m-d H:i:s');
        }
        return $this->query()->where($this->primaryKey, $id)->update($data) > 0;
    }

    /** Soft delete quando habilitado; exclusão física caso contrário. */
    public function delete(int $id, ?int $byUserId = null): bool
    {
        if ($this->softDeletes) {
            $data = ['deleted_at' => gmdate('Y-m-d H:i:s')];
            if ($byUserId !== null) {
                $data['deleted_by'] = $byUserId;
            }
            return $this->query()->where($this->primaryKey, $id)->update($data) > 0;
        }
        return $this->query()->where($this->primaryKey, $id)->delete() > 0;
    }

    public function restore(int $id): bool
    {
        if (!$this->softDeletes) {
            return false;
        }
        return $this->withTrashed()->query()->where($this->primaryKey, $id)->whereNotNull('deleted_at')
            ->update(['deleted_at' => null, 'deleted_by' => null]) > 0;
    }

    public function forceDelete(int $id): bool
    {
        return $this->withTrashed()->query()->where($this->primaryKey, $id)->delete() > 0;
    }

    /** Filtra pelo $fillable, criptografa e serializa JSON. */
    /** @param array<string,mixed> $data
     *  @return array<string,mixed> */
    protected function prepare(array $data): array
    {
        if ($this->fillable !== []) {
            $data = array_intersect_key($data, array_flip($this->fillable));
        }
        foreach ($this->encrypted as $column) {
            if (array_key_exists($column, $data)) {
                $value = $data[$column];
                $data[$column] = ($value === null || $value === '') ? null : Crypto::encrypt((string) $value);
            }
        }
        foreach ($this->json as $column) {
            if (array_key_exists($column, $data) && !is_string($data[$column])) {
                $data[$column] = $data[$column] === null ? null : json_encode($data[$column], JSON_UNESCAPED_UNICODE);
            }
        }
        foreach ($data as $k => $v) {
            if (is_bool($v)) {
                $data[$k] = (int) $v;
            }
        }
        return $data;
    }

    /** Descriptografa e decodifica JSON na leitura. */
    /** @param array<string,mixed> $row
     *  @return array<string,mixed> */
    public function castRow(array $row): array
    {
        foreach ($this->encrypted as $column) {
            if (isset($row[$column]) && is_string($row[$column])) {
                $row[$column] = Crypto::tryDecrypt($row[$column]);
            }
        }
        foreach ($this->json as $column) {
            if (isset($row[$column]) && is_string($row[$column])) {
                $decoded = json_decode($row[$column], true);
                $row[$column] = is_array($decoded) ? $decoded : null;
            }
        }
        return $row;
    }
}
