<?php
// app/Core/Query.php
declare(strict_types=1);

namespace App\Core;

/**
 * Construtor de consultas mínimo, sempre com placeholders. Usado pelo Model base.
 * Não cobre JOINs complexos: para relatórios, escreva SQL direto em Database::select() com parâmetros.
 */
final class Query
{
    /** @var list<string> */
    private array $wheres = [];
    /** @var list<mixed> */
    private array $bindings = [];
    /** @var list<string> */
    private array $orders = [];
    private ?int $limit = null;
    private int $offset = 0;
    /** @var list<string> */
    private array $columns = ['*'];
    private ?string $groupBy = null;

    public function __construct(private readonly string $table)
    {
    }

    /** @param list<string> $columns */
    public function select(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    /**
     * where('status', 'paid') | where('amount', '>=', 10) | where('deleted_at', null) | where(['a' => 1, 'b >' => 2])
     */
    public function where(string|array $column, mixed $operator = null, mixed $value = null): self
    {
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $parts = preg_split('/\s+/', trim((string) $key), 2) ?: [$key];
                $this->where($parts[0], $parts[1] ?? '=', $val);
            }
            return $this;
        }
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        $operator = strtoupper(trim((string) $operator));
        if (!in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'], true)) {
            throw new \InvalidArgumentException("Operador inválido: {$operator}");
        }
        $col = $this->quoteColumn($column);
        if ($value === null) {
            $this->wheres[] = $col . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL');
            return $this;
        }
        $this->wheres[] = "{$col} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /** @param list<mixed> $values */
    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            $this->wheres[] = '1 = 0';
            return $this;
        }
        $this->wheres[] = $this->quoteColumn($column) . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        foreach ($values as $v) {
            $this->bindings[] = $v;
        }
        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = $this->quoteColumn($column) . ' IS NULL';
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = $this->quoteColumn($column) . ' IS NOT NULL';
        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): self
    {
        $this->wheres[] = $this->quoteColumn($column) . ' BETWEEN ? AND ?';
        $this->bindings[] = $from;
        $this->bindings[] = $to;
        return $this;
    }

    /** Condição SQL bruta com placeholders posicionais (use só com colunas fixas, nunca com entrada do usuário no SQL). */
    /** @param list<mixed> $bindings */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = '(' . $sql . ')';
        foreach ($bindings as $b) {
            $this->bindings[] = $b;
        }
        return $this;
    }

    /** Busca textual (LIKE) em uma ou mais colunas. */
    /** @param list<string> $columns */
    public function search(array $columns, string $term): self
    {
        $term = trim($term);
        if ($term === '' || $columns === []) {
            return $this;
        }
        $parts = [];
        foreach ($columns as $c) {
            $parts[] = $this->quoteColumn($c) . ' LIKE ?';
            $this->bindings[] = '%' . addcslashes($term, '%_\\') . '%';
        }
        $this->wheres[] = '(' . implode(' OR ', $parts) . ')';
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = $this->quoteColumn($column) . ' ' . $direction;
        return $this;
    }

    public function groupBy(string $column): self
    {
        $this->groupBy = $this->quoteColumn($column);
        return $this;
    }

    public function limit(?int $limit, int $offset = 0): self
    {
        $this->limit = $limit;
        $this->offset = max(0, $offset);
        return $this;
    }

    /** @return array<int,array<string,mixed>> */
    public function get(): array
    {
        return Database::select($this->toSql(), $this->bindings);
    }

    /** @return array<string,mixed>|null */
    public function first(): ?array
    {
        $this->limit = 1;
        $rows = $this->get();
        return $rows[0] ?? null;
    }

    public function count(): int
    {
        $sql = 'SELECT COUNT(*) FROM `' . $this->table . '`' . $this->whereSql();
        return (int) Database::scalar($sql, $this->bindings);
    }

    public function sum(string $column): string
    {
        $sql = 'SELECT COALESCE(SUM(' . $this->quoteColumn($column) . '), 0) FROM `' . $this->table . '`' . $this->whereSql();
        return (string) Database::scalar($sql, $this->bindings);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /** @param array<string,mixed> $data */
    public function update(array $data): int
    {
        if ($this->wheres === []) {
            throw new \LogicException('UPDATE sem WHERE não é permitido.');
        }
        $sets = [];
        $bindings = [];
        foreach ($data as $column => $value) {
            $sets[] = $this->quoteColumn($column) . ' = ?';
            $bindings[] = is_bool($value) ? (int) $value : $value;
        }
        $sql = 'UPDATE `' . $this->table . '` SET ' . implode(', ', $sets) . $this->whereSql();
        return Database::execute($sql, array_merge($bindings, $this->bindings));
    }

    public function delete(): int
    {
        if ($this->wheres === []) {
            throw new \LogicException('DELETE sem WHERE não é permitido.');
        }
        return Database::execute('DELETE FROM `' . $this->table . '`' . $this->whereSql(), $this->bindings);
    }

    public function toSql(): string
    {
        $cols = implode(', ', array_map(fn(string $c): string => $c === '*' ? '*' : $this->quoteColumn($c), $this->columns));
        $sql = 'SELECT ' . $cols . ' FROM `' . $this->table . '`' . $this->whereSql();
        if ($this->groupBy !== null) {
            $sql .= ' GROUP BY ' . $this->groupBy;
        }
        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . (int) $this->limit . ' OFFSET ' . (int) $this->offset;
        }
        return $sql;
    }

    /** @return list<mixed> */
    public function bindings(): array
    {
        return $this->bindings;
    }

    private function whereSql(): string
    {
        return $this->wheres === [] ? '' : ' WHERE ' . implode(' AND ', $this->wheres);
    }

    /** Aceita "coluna", "tabela.coluna" e expressões simples já entre crases ou funções (ex.: "SUM(amount)"). */
    private function quoteColumn(string $column): string
    {
        $column = trim($column);
        if (str_contains($column, '(') || str_starts_with($column, '`')) {
            return $column;
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $column)) {
            throw new \InvalidArgumentException("Nome de coluna inválido: {$column}");
        }
        return '`' . str_replace('.', '`.`', $column) . '`';
    }
}
