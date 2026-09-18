<?php
// tests/Support.php — fixtures e utilitários compartilhados pelos testes de integração (carregado com require_once)
declare(strict_types=1);

use App\Core\Database;

/** Banco configurado, acessível e com o schema aplicado? */
function db_available(string $table = 'deletion_requests'): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        $cache[$table] = Database::isConfigured() && Database::ping() && Database::scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", [$table]) > 0;
    }
    return $cache[$table];
}

function privacy_db_available(): bool
{
    return db_available('deletion_requests');
}

/** Cria usuário + lar (individual ou com um segundo membro) para os testes; devolve ids. */
function privacy_fixture(bool $withMember = false): array
{
    $suffix = bin2hex(random_bytes(4));
    $now = gmdate('Y-m-d H:i:s');
    $owner = Database::insert('users', ['name' => 'Tit ' . $suffix, 'email' => "tit-{$suffix}@teste.invalid", 'email_verified_at' => $now, 'password_hash' => 'x', 'color' => '#000000', 'adult_confirmed_at' => $now, 'status' => 'active', 'created_at' => $now]);
    $household = Database::insert('households', ['name' => 'Lar ' . $suffix, 'type' => $withMember ? 'family' : 'individual', 'currency' => 'BRL', 'fiscal_month_start_day' => 1, 'owner_user_id' => $owner, 'status' => 'active', 'created_at' => $now]);
    Database::insert('household_members', ['household_id' => $household, 'user_id' => $owner, 'role' => 'owner', 'joined_at' => $now, 'created_at' => $now]);
    $member = null;
    if ($withMember) {
        $member = Database::insert('users', ['name' => 'Mem ' . $suffix, 'email' => "mem-{$suffix}@teste.invalid", 'email_verified_at' => $now, 'password_hash' => 'x', 'color' => '#111111', 'adult_confirmed_at' => $now, 'status' => 'active', 'created_at' => $now]);
        Database::insert('household_members', ['household_id' => $household, 'user_id' => $member, 'role' => 'member', 'joined_at' => $now, 'created_at' => $now]);
    }
    $account = Database::insert('accounts', ['household_id' => $household, 'name' => 'Conta', 'type' => 'checking', 'owner_user_id' => $member ?? $owner, 'created_at' => $now]);
    $joint = Database::insert('accounts', ['household_id' => $household, 'name' => 'Conjunta', 'type' => 'cash', 'owner_user_id' => null, 'created_at' => $now]);
    $by = $member ?? $owner;
    Database::insert('transactions', ['household_id' => $household, 'account_id' => $account, 'category_id' => null, 'responsible_user_id' => $by, 'created_by' => $by, 'type' => 'expense', 'amount' => '10.00', 'date' => '2026-09-01', 'description' => 'Padaria', 'status' => 'paid', 'created_at' => $now]);
    Database::insert('transactions', ['household_id' => $household, 'account_id' => $joint, 'category_id' => null, 'responsible_user_id' => $by, 'created_by' => $by, 'type' => 'expense', 'amount' => '20.00', 'date' => '2026-09-02', 'description' => 'Mercado', 'status' => 'paid', 'created_at' => $now]);
    Database::insert('consents', ['user_id' => $by, 'kind' => 'terms', 'granted' => 1, 'document_version' => '1.0', 'ip' => '10.0.0.1', 'user_agent' => 'ua', 'created_at' => $now]);
    return ['owner' => $owner, 'member' => $member, 'household' => $household, 'account' => $account, 'joint' => $joint, 'email' => "tit-{$suffix}@teste.invalid"];
}
