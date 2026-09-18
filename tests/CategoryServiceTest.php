<?php
// tests/CategoryServiceTest.php — normalização de descrições e sugestão aprendida (parte com banco, pulada se indisponível)
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

use App\Core\Database;
use App\Services\CategoryService;

function test_category_normalize_strips_noise(): void
{
    assert_same('mercado bom preco', CategoryService::normalize('COMPRA CARTÃO 12/09 MERCADO BOM PREÇO LTDA'));
    assert_same('joao silva', CategoryService::normalize('PIX RECEBIDO João Silva 123456'));
    assert_same('', CategoryService::normalize('123 456'));
}

function test_category_learn_and_suggest(): void
{
    if (!db_available('category_rules')) { return; }
    $f = privacy_fixture();
    $h = (int) $f['household'];
    $cat = (int) Database::scalar("SELECT id FROM categories WHERE household_id IS NULL AND kind = 'expense' AND parent_id IS NOT NULL ORDER BY id LIMIT 1");
    $other = (int) Database::scalar("SELECT id FROM categories WHERE household_id IS NULL AND kind = 'expense' AND parent_id IS NOT NULL AND id <> ? ORDER BY id LIMIT 1", [$cat]);
    assert_null(CategoryService::suggest($h, 'Mercado Bom Preço'), 'sem regra, sem sugestão');
    CategoryService::learn($h, 'COMPRA CARTAO MERCADO BOM PRECO', $cat);
    assert_same($cat, CategoryService::suggest($h, 'Mercado Bom Preço 05/09'), 'sugere pela descrição normalizada');
    CategoryService::learn($h, 'COMPRA CARTAO MERCADO BOM PRECO', $cat);
    assert_same(2, (int) Database::scalar('SELECT hits FROM category_rules WHERE household_id = ? AND category_id = ?', [$h, $cat]));
    CategoryService::learn($h, 'Mercado Bom Preço', $other);
    assert_same($other, CategoryService::suggest($h, 'mercado bom preco'), 'mudar de ideia troca a regra');
    $many = CategoryService::suggestMany($h, ['Mercado Bom Preço', 'Coisa desconhecida']);
    assert_same($other, $many['Mercado Bom Preço']);
    assert_null($many['Coisa desconhecida'] ?? null);
    assert_null(CategoryService::suggest(999999, 'Mercado Bom Preço'), 'regras são por lar');
}
