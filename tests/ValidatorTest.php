<?php
// tests/ValidatorTest.php
declare(strict_types=1);

use App\Core\Validator;

function test_validator_required_and_email(): void
{
    $v = Validator::make(['email' => 'X@Exemplo.COM', 'name' => ''], ['email' => 'required|email', 'name' => 'required|max:10'], ['name' => 'nome']);
    assert_true($v->fails());
    assert_same(['name' => ['O campo nome é obrigatório.']], $v->errors());
    $v2 = Validator::make(['email' => 'x@exemplo.com'], ['email' => 'required|email']);
    assert_false($v2->fails());
    assert_same('x@exemplo.com', $v2->validated()['email']);
}

function test_validator_money_and_date_br_normalize(): void
{
    $v = Validator::make(
        ['amount' => 'R$ 1.234,56', 'date' => '31/12/2026', 'neg' => '-10,5'],
        ['amount' => 'required|money', 'date' => 'required|date_br', 'neg' => 'money']
    );
    assert_false($v->fails(), (string) $v->firstError());
    assert_same('1234.56', $v->validated()['amount']);
    assert_same('2026-12-31', $v->validated()['date']);
    assert_same('-10.50', $v->validated()['neg']);
    assert_null(Validator::parseMoney('abc'));
    assert_same('1234.50', Validator::parseMoney('1234.5'));
    assert_same('1234567.89', Validator::parseMoney('1.234.567,89'));
}

function test_validator_password_strength(): void
{
    assert_true(Validator::make(['p' => 'senha123'], ['p' => 'password'])->fails(), 'curta demais');
    assert_true(Validator::make(['p' => 'password12345'], ['p' => 'password'])->fails(), 'comum com dígitos');
    assert_true(Validator::make(['p' => 'abcdefghijkl'], ['p' => 'password'])->fails(), 'sem número');
    assert_false(Validator::make(['p' => 'Cofre@2026teste'], ['p' => 'password'])->fails());
}

function test_validator_nullable_in_boolean_confirmed(): void
{
    $v = Validator::make(
        ['role' => 'admin', 'flag' => 'on', 'pw' => 'abc', 'pw_confirmation' => 'abd', 'opt' => ''],
        ['role' => 'in:owner,admin', 'flag' => 'boolean', 'pw' => 'confirmed', 'opt' => 'nullable|integer']
    );
    assert_true($v->fails());
    assert_same(['pw' => ['A confirmação de pw não confere.']], $v->errors());
    $v2 = Validator::make(['flag' => 'on', 'opt' => ''], ['flag' => 'boolean', 'opt' => 'nullable|integer']);
    assert_false($v2->fails());
    assert_same(true, $v2->validated()['flag']);
    assert_null($v2->validated()['opt']);
}

function test_validator_cpf_and_color(): void
{
    assert_true(Validator::isValidCpf('52998224725'));
    assert_false(Validator::isValidCpf('11111111111'));
    assert_false(Validator::isValidCpf('12345678900'));
    $v = Validator::make(['c' => '#ABCDEF'], ['c' => 'color']);
    assert_false($v->fails());
    assert_same('#abcdef', $v->validated()['c']);
}
