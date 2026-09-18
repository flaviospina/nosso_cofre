<?php
// app/Services/CategoryService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Sugestão de categoria aprendida do histórico (descrição → categoria) por lar.
 * Cada vez que o usuário categoriza um lançamento, o padrão (descrição normalizada) ganha um "hit".
 */
final class CategoryService
{
    /** Normaliza a descrição: minúsculas, sem acentos/dígitos/pontuação, espaços únicos. */
    public static function normalize(string $description): string
    {
        $s = mb_strtolower(trim($description));
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        $s = preg_replace('/[0-9]+/', ' ', $s) ?? $s;
        $s = preg_replace('/[^a-z\s]/', ' ', $s) ?? $s;
        $s = preg_replace('/\b(pix|ted|doc|compra|pagamento|pgto|pag|debito|credito|cartao|recebido|enviado|transferencia|transf|parcela|cp|no|na|em|de|do|da|ltda|me|sa|s a)\b/', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /** Aprende: descrição → categoria. */
    public static function learn(int $householdId, string $description, int $categoryId): void
    {
        $pattern = self::normalize($description);
        if (mb_strlen($pattern) < 3) {
            return;
        }
        $pattern = mb_substr($pattern, 0, 190);
        $existing = Database::selectOne('SELECT id, category_id, hits FROM category_rules WHERE household_id = ? AND pattern = ? AND match_type = ?', [$householdId, $pattern, 'contains']);
        if ($existing === null) {
            Database::insert('category_rules', ['household_id' => $householdId, 'pattern' => $pattern, 'match_type' => 'contains', 'category_id' => $categoryId, 'source' => 'learned', 'hits' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);
        } elseif ((int) $existing['category_id'] === $categoryId) {
            Database::execute('UPDATE category_rules SET hits = hits + 1 WHERE id = ?', [(int) $existing['id']]);
        } else {
            // O usuário mudou de ideia: a regra passa a apontar para a nova categoria
            Database::execute('UPDATE category_rules SET category_id = ?, hits = 1 WHERE id = ?', [$categoryId, (int) $existing['id']]);
        }
    }

    /** Sugere a categoria: regra manual exata > padrão contido mais longo/mais usado. */
    public static function suggest(int $householdId, string $description): ?int
    {
        $normalized = self::normalize($description);
        if ($normalized === '') {
            return null;
        }
        $rules = Database::select(
            'SELECT r.pattern, r.match_type, r.category_id, r.hits FROM category_rules r JOIN categories c ON c.id = r.category_id AND c.deleted_at IS NULL AND c.is_active = 1
              WHERE r.household_id = ? ORDER BY r.source = ? DESC, CHAR_LENGTH(r.pattern) DESC, r.hits DESC',
            [$householdId, 'manual']
        );
        foreach ($rules as $rule) {
            $p = (string) $rule['pattern'];
            $match = match ($rule['match_type']) {
                'exact'  => $normalized === $p,
                'starts' => str_starts_with($normalized, $p),
                default  => $p !== '' && (str_contains($normalized, $p) || str_contains($p, $normalized)),
            };
            if ($match) {
                return (int) $rule['category_id'];
            }
        }
        return null;
    }

    /** Sugestões em lote (importação). */
    /** @param list<string> $descriptions
     *  @return array<string,int|null> descrição → categoria */
    public static function suggestMany(int $householdId, array $descriptions): array
    {
        $out = [];
        foreach (array_unique($descriptions) as $d) {
            $out[$d] = self::suggest($householdId, $d);
        }
        return $out;
    }
}
