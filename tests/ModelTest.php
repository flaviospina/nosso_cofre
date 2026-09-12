<?php
// tests/ModelTest.php — o escopo por lar é a regra de segurança central: testado sem banco, via SQL gerado
declare(strict_types=1);

use App\Core\Model;
use App\Core\Query;

final class FakeScopedModel extends Model
{
    protected string $table = 'transactions';
    protected bool $softDeletes = true;

    public function sqlFor(int $id): array
    {
        $q = $this->query()->where('id', $id);
        return [$q->toSql(), $q->bindings()];
    }
}

function test_model_injects_household_scope_and_soft_delete(): void
{
    $model = FakeScopedModel::forHousehold(42);
    [$sql, $bindings] = $model->sqlFor(7);
    assert_contains('`household_id` = ?', $sql);
    assert_contains('`deleted_at` IS NULL', $sql);
    assert_same([42, 7], $bindings);
}

function test_model_without_household_throws(): void
{
    $model = new FakeScopedModel();
    assert_throws(LogicException::class, static fn() => $model->sqlFor(1), 'sem lar definido não pode consultar');
}

function test_query_builder_rejects_unsafe_column_and_operator(): void
{
    $q = new Query('accounts');
    assert_throws(InvalidArgumentException::class, static fn() => $q->where('id; DROP TABLE x', 1));
    assert_throws(InvalidArgumentException::class, static fn() => $q->where('id', 'UNION', 1));
    assert_throws(LogicException::class, static fn() => (new Query('accounts'))->update(['name' => 'x']), 'update sem where');
}

function test_query_builder_where_in_and_search(): void
{
    $q = (new Query('transactions'))->whereIn('status', ['paid', 'pending'])->search(['description'], '50%')->orderBy('date', 'desc')->limit(10, 20);
    assert_same('SELECT * FROM `transactions` WHERE `status` IN (?, ?) AND (`description` LIKE ?) ORDER BY `date` DESC LIMIT 10 OFFSET 20', $q->toSql());
    assert_same(['paid', 'pending', '%50\\%%'], $q->bindings());
}
