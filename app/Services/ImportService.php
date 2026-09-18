<?php
// app/Services/ImportService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Models\ImportBatch;
use App\Models\Transaction;
use DateTimeImmutable;
use RuntimeException;

/**
 * Importação de extratos CSV e OFX em dois passos: envio (arquivo fica em storage/cache) → mapeamento/pré-visualização → confirmação.
 * Duplicados: hash(data|valor|tipo|descrição) já existente no lar, ou FITID do OFX já importado.
 * Categoria sugerida pelas regras aprendidas (CategoryService).
 */
final class ImportService
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const DATE_FORMATS = ['d/m/Y' => 'dd/mm/aaaa', 'Y-m-d' => 'aaaa-mm-dd', 'm/d/Y' => 'mm/dd/aaaa', 'd/m/y' => 'dd/mm/aa', 'Ymd' => 'aaaammdd'];

    /** Guarda o arquivo enviado e devolve a chave de trabalho + informações para o mapeamento. */
    /** @param array<string,mixed> $file
     *  @return array<string,mixed> */
    public static function stage(array $file, int $userId): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo deve ter no máximo 2 MB.',
                UPLOAD_ERR_NO_FILE => 'Escolha um arquivo CSV ou OFX.',
                default => 'Falha no envio do arquivo. Tente de novo.',
            });
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('O arquivo deve ter no máximo 2 MB.');
        }
        $raw = (string) file_get_contents((string) $file['tmp_name']);
        $raw = self::toUtf8($raw);
        $name = (string) ($file['name'] ?? 'extrato');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $format = ($ext === 'ofx' || $ext === 'qfx' || stripos($raw, '<OFX>') !== false || stripos($raw, 'OFXHEADER') !== false) ? 'ofx' : 'csv';
        $key = 'u' . $userId . '-' . Crypto::randomToken(12);
        $dir = (string) Config::get('paths.cache');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($dir . '/import-' . $key . '.' . $format, $raw);
        file_put_contents($dir . '/import-' . $key . '.json', json_encode(['filename' => mb_substr($name, 0, 190), 'format' => $format, 'user_id' => $userId, 'created_at' => time()]));
        return ['key' => $key, 'format' => $format, 'filename' => $name];
    }

    /** @return array<string,mixed>|null meta + caminho do arquivo */
    public static function load(string $key, int $userId): ?array
    {
        if (!preg_match('/^u\d+-[a-f0-9]{24}$/', $key)) {
            return null;
        }
        $dir = (string) Config::get('paths.cache');
        $meta = @json_decode((string) @file_get_contents($dir . '/import-' . $key . '.json'), true);
        if (!is_array($meta) || (int) $meta['user_id'] !== $userId) {
            return null;
        }
        $meta['path'] = $dir . '/import-' . $key . '.' . $meta['format'];
        if (!is_file($meta['path'])) {
            return null;
        }
        return $meta;
    }

    public static function discard(string $key): void
    {
        $dir = (string) Config::get('paths.cache');
        foreach (glob($dir . '/import-' . $key . '.*') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** Remove arquivos de importação com mais de 24 h (cron). */
    public static function purgeStale(): int
    {
        $n = 0;
        foreach (glob((string) Config::get('paths.cache') . '/import-*') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
                $n++;
            }
        }
        return $n;
    }

    // --- CSV ---

    /** Analisa o CSV: delimitador, cabeçalho, colunas e amostra; sugere o mapeamento. */
    /** @return array<string,mixed> */
    public static function inspectCsv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_values(array_filter($lines, static fn(string $l): bool => trim($l) !== ''));
        $delimiter = self::detectDelimiter(array_slice($lines, 0, 5));
        $rows = [];
        foreach (array_slice($lines, 0, 8) as $line) {
            $rows[] = str_getcsv($line, $delimiter, '"', '\\');
        }
        $first = $rows[0] ?? [];
        $hasHeader = self::looksLikeHeader($first);
        $columns = [];
        $width = max(array_map('count', $rows ?: [[]]));
        for ($i = 0; $i < $width; $i++) {
            $columns[$i] = $hasHeader ? trim((string) ($first[$i] ?? "Coluna " . ($i + 1))) : 'Coluna ' . ($i + 1);
        }
        $guess = self::guessMapping($columns, $hasHeader ? array_slice($rows, 1) : $rows);
        return [
            'delimiter'  => $delimiter,
            'has_header' => $hasHeader,
            'columns'    => $columns,
            'sample'     => $hasHeader ? array_slice($rows, 1, 5) : array_slice($rows, 0, 5),
            'total'      => count($lines) - ($hasHeader ? 1 : 0),
            'mapping'    => $guess,
        ];
    }

    /** @param list<string> $lines */
    private static function detectDelimiter(array $lines): string
    {
        $best = ';';
        $bestScore = -1;
        foreach ([';', ',', "\t", '|'] as $d) {
            $counts = array_map(static fn(string $l): int => substr_count($l, $d), $lines);
            if ($counts === []) {
                continue;
            }
            $min = min($counts);
            $score = $min > 0 ? $min * 10 - (max($counts) - $min) : -1;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }
        return $best;
    }

    /** @param list<string|null> $row */
    private static function looksLikeHeader(array $row): bool
    {
        $text = 0;
        foreach ($row as $cell) {
            $cell = trim((string) $cell);
            if ($cell !== '' && !preg_match('/^[\d.,\/\-+ R$]+$/', $cell)) {
                $text++;
            }
        }
        return $row !== [] && $text >= max(2, (int) ceil(count($row) * 0.6));
    }

    /** @param array<int,string> $columns
     *  @param array<int,array<int,string|null>> $sample
     *  @return array<string,mixed> */
    private static function guessMapping(array $columns, array $sample): array
    {
        $map = ['date' => null, 'description' => null, 'amount' => null, 'debit' => null, 'credit' => null, 'type' => null, 'date_format' => 'd/m/Y', 'invert' => false];
        foreach ($columns as $i => $name) {
            $n = mb_strtolower($name);
            if ($map['date'] === null && preg_match('/data|date|dt/', $n)) {
                $map['date'] = $i;
            } elseif ($map['description'] === null && preg_match('/descri|hist|memo|lan[cç]amento|estabelecimento|title|detalhe/', $n)) {
                $map['description'] = $i;
            } elseif ($map['debit'] === null && preg_match('/d[ée]bito|sa[ií]da|debit/', $n)) {
                $map['debit'] = $i;
            } elseif ($map['credit'] === null && preg_match('/cr[ée]dito|entrada|credit/', $n)) {
                $map['credit'] = $i;
            } elseif ($map['amount'] === null && preg_match('/valor|amount|montante|quantia/', $n)) {
                $map['amount'] = $i;
            } elseif ($map['type'] === null && preg_match('/tipo|type|natureza/', $n)) {
                $map['type'] = $i;
            }
        }
        // Sem cabeçalho útil: deduz pelo conteúdo (data / número / texto)
        if ($map['date'] === null || ($map['amount'] === null && $map['debit'] === null)) {
            foreach ($columns as $i => $name) {
                $values = array_map(static fn(array $r): string => trim((string) ($r[$i] ?? '')), $sample);
                $values = array_filter($values);
                if ($values === []) {
                    continue;
                }
                $dates = count(array_filter($values, static fn(string $v): bool => preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$|^\d{4}-\d{2}-\d{2}$|^\d{8}$/', $v) === 1));
                $nums = count(array_filter($values, static fn(string $v): bool => preg_match('/^-?R?\$?\s?[\d.]+,?\d*$|^-?[\d,]+\.?\d*$/', $v) === 1));
                if ($map['date'] === null && $dates === count($values)) {
                    $map['date'] = $i;
                } elseif ($map['amount'] === null && $map['debit'] === null && $nums === count($values)) {
                    $map['amount'] = $i;
                } elseif ($map['description'] === null && $dates === 0 && $nums === 0) {
                    $map['description'] = $i;
                }
            }
        }
        $dateCol = $map['date'];
        if ($dateCol !== null) {
            foreach ($sample as $r) {
                $v = trim((string) ($r[$dateCol] ?? ''));
                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) { $map['date_format'] = 'Y-m-d'; break; }
                if (preg_match('/^\d{8}$/', $v)) { $map['date_format'] = 'Ymd'; break; }
                if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2}$/', $v)) { $map['date_format'] = 'd/m/y'; break; }
            }
        }
        return $map;
    }

    /**
     * Converte o CSV em linhas normalizadas segundo o mapeamento.
     * @param array<string,mixed> $mapping
     * @return array{rows:list<array<string,mixed>>,errors:list<string>}
     */
    public static function parseCsv(string $path, array $mapping): array
    {
        $content = (string) file_get_contents($path);
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_values(array_filter($lines, static fn(string $l): bool => trim($l) !== ''));
        $delimiter = (string) ($mapping['delimiter'] ?? ';');
        if (!empty($mapping['has_header'])) {
            array_shift($lines);
        }
        $rows = [];
        $errors = [];
        foreach ($lines as $n => $line) {
            $cells = str_getcsv($line, $delimiter, '"', '\\');
            $get = static fn($idx): string => $idx === null || $idx === '' ? '' : trim((string) ($cells[(int) $idx] ?? ''));
            $date = self::parseDate($get($mapping['date'] ?? null), (string) ($mapping['date_format'] ?? 'd/m/Y'));
            if ($date === null) {
                $errors[] = 'Linha ' . ($n + 1) . ': data inválida ("' . $get($mapping['date'] ?? null) . '").';
                continue;
            }
            $description = $get($mapping['description'] ?? null);
            $amount = null;
            $type = null;
            if (($mapping['debit'] ?? '') !== '' && $mapping['debit'] !== null) {
                $debit = self::parseAmount($get($mapping['debit']));
                $credit = ($mapping['credit'] ?? '') !== '' && $mapping['credit'] !== null ? self::parseAmount($get($mapping['credit'])) : null;
                if ($debit !== null && $debit != 0) {
                    $amount = abs($debit);
                    $type = 'expense';
                } elseif ($credit !== null && $credit != 0) {
                    $amount = abs($credit);
                    $type = 'income';
                }
            } else {
                $value = self::parseAmount($get($mapping['amount'] ?? null));
                if ($value !== null) {
                    $amount = abs($value);
                    $type = $value < 0 ? 'expense' : 'income';
                    if (($mapping['type'] ?? '') !== '' && $mapping['type'] !== null) {
                        $t = mb_strtolower($get($mapping['type']));
                        if (preg_match('/d[ée]b|sa[ií]da|despesa|pagamento|-/', $t)) {
                            $type = 'expense';
                        } elseif (preg_match('/cr[ée]d|entrada|receita|dep[óo]sito|\+/', $t)) {
                            $type = 'income';
                        }
                    }
                }
            }
            if ($amount === null || $amount == 0) {
                $errors[] = 'Linha ' . ($n + 1) . ': valor inválido ou zero.';
                continue;
            }
            if (!empty($mapping['invert'])) {
                $type = $type === 'expense' ? 'income' : 'expense';
            }
            $rows[] = ['date' => $date, 'description' => $description !== '' ? mb_substr($description, 0, 190) : 'Sem descrição', 'amount' => number_format($amount, 2, '.', ''), 'type' => $type, 'fitid' => null];
        }
        return ['rows' => $rows, 'errors' => $errors];
    }

    // --- OFX ---

    /** @return array{rows:list<array<string,mixed>>,errors:list<string>,account:?string} */
    public static function parseOfx(string $path): array
    {
        $content = (string) file_get_contents($path);
        $rows = [];
        $errors = [];
        preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $content, $matches);
        foreach ($matches[1] as $i => $block) {
            $tag = static function (string $name) use ($block): string {
                return preg_match('/<' . $name . '>([^<\r\n]*)/i', $block, $m) ? trim(html_entity_decode($m[1])) : '';
            };
            $posted = $tag('DTPOSTED');
            $date = preg_match('/^(\d{4})(\d{2})(\d{2})/', $posted, $d) ? "{$d[1]}-{$d[2]}-{$d[3]}" : null;
            $amount = self::parseAmount($tag('TRNAMT'));
            if ($date === null || $amount === null || $amount == 0) {
                $errors[] = 'Transação ' . ($i + 1) . ' ignorada (data ou valor inválido).';
                continue;
            }
            $desc = $tag('MEMO') !== '' ? $tag('MEMO') : ($tag('NAME') !== '' ? $tag('NAME') : 'Sem descrição');
            $rows[] = ['date' => $date, 'description' => mb_substr($desc, 0, 190), 'amount' => number_format(abs($amount), 2, '.', ''), 'type' => $amount < 0 ? 'expense' : 'income', 'fitid' => $tag('FITID') !== '' ? mb_substr($tag('FITID'), 0, 190) : null];
        }
        $account = preg_match('/<ACCTID>([^<\r\n]*)/i', $content, $m) ? trim($m[1]) : null;
        return ['rows' => $rows, 'errors' => $errors, 'account' => $account];
    }

    // --- Duplicados e sugestões ---

    /** Marca duplicados (hash já existente no lar ou repetido no próprio arquivo) e sugere categorias. */
    /** @param list<array<string,mixed>> $rows
     *  @return list<array<string,mixed>> */
    public static function enrich(array $rows, int $householdId): array
    {
        $hashes = [];
        foreach ($rows as $r) {
            $hashes[] = Transaction::importHash($r['date'], $r['amount'], $r['description'], $r['type']);
        }
        $existing = [];
        if ($hashes !== []) {
            $placeholders = implode(',', array_fill(0, count($hashes), '?'));
            foreach (Database::select("SELECT import_hash FROM transactions WHERE household_id = ? AND import_hash IN ({$placeholders})", array_merge([$householdId], $hashes)) as $row) {
                $existing[(string) $row['import_hash']] = true;
            }
        }
        $fitids = array_values(array_filter(array_map(static fn(array $r) => $r['fitid'], $rows)));
        $seenFit = [];
        if ($fitids !== []) {
            $placeholders = implode(',', array_fill(0, count($fitids), '?'));
            foreach (Database::select("SELECT JSON_EXTRACT(tags, '$.fitid') AS f FROM transactions WHERE household_id = ? AND JSON_EXTRACT(tags, '$.fitid') IN ({$placeholders})", array_merge([$householdId], array_map(static fn(string $f): string => json_encode($f), $fitids))) as $row) {
                $seenFit[trim((string) $row['f'], '"')] = true;
            }
        }
        $suggestions = CategoryService::suggestMany($householdId, array_map(static fn(array $r): string => (string) $r['description'], $rows));
        $inFile = [];
        foreach ($rows as $i => &$r) {
            $h = $hashes[$i];
            $r['hash'] = $h;
            $r['duplicate'] = isset($existing[$h]) || isset($inFile[$h]) || ($r['fitid'] !== null && isset($seenFit[$r['fitid']]));
            $inFile[$h] = true;
            $r['suggested_category_id'] = $suggestions[$r['description']] ?? null;
        }
        unset($r);
        return $rows;
    }

    /**
     * Grava as linhas selecionadas como lançamentos.
     * @param list<array<string,mixed>> $rows linhas já enriquecidas
     * @param array<int,int|null> $categories índice → categoria escolhida
     * @param list<int> $selected índices a importar
     * @return array{batch_id:int,imported:int,duplicated:int}
     */
    public static function commit(array $rows, array $selected, array $categories, int $accountId, ?int $responsibleId, string $filename, string $format, array $mapping, string $status = 'paid'): array
    {
        $householdId = (int) Auth::householdId();
        $userId = (int) Auth::id();
        $batches = new ImportBatch();
        $batchId = $batches->create([
            'user_id' => $userId, 'account_id' => $accountId, 'filename' => $filename, 'format' => $format, 'mapping' => $mapping,
            'rows_total' => count($rows), 'rows_imported' => 0, 'rows_duplicated' => count(array_filter($rows, static fn(array $r): bool => (bool) $r['duplicate'])), 'status' => 'pending',
        ]);
        $imported = 0;
        $model = new Transaction();
        Database::transaction(static function () use ($rows, $selected, $categories, $accountId, $responsibleId, $status, $batchId, $userId, $householdId, $model, &$imported): void {
            foreach ($selected as $i) {
                if (!isset($rows[$i])) {
                    continue;
                }
                $r = $rows[$i];
                $categoryId = $categories[$i] ?? null;
                $model->create([
                    'account_id' => $accountId, 'category_id' => $categoryId, 'responsible_user_id' => $responsibleId, 'created_by' => $userId,
                    'type' => $r['type'], 'amount' => $r['amount'], 'date' => $r['date'], 'paid_at' => $status === 'paid' ? $r['date'] : null,
                    'description' => $r['description'], 'status' => $status, 'tags' => $r['fitid'] !== null ? ['fitid' => $r['fitid']] : null,
                    'import_batch_id' => $batchId, 'import_hash' => $r['hash'],
                ]);
                if ($categoryId !== null) {
                    CategoryService::learn($householdId, (string) $r['description'], (int) $categoryId);
                }
                $imported++;
            }
        });
        $batches->update($batchId, ['rows_imported' => $imported, 'status' => 'done']);
        AuditService::log('import.commit', 'import_batch', $batchId, null, ['filename' => $filename, 'imported' => $imported]);
        return ['batch_id' => $batchId, 'imported' => $imported, 'duplicated' => count($rows) - count($selected)];
    }

    /** Desfaz um lote: manda os lançamentos importados para a lixeira. */
    public static function undo(int $batchId): int
    {
        $batches = new ImportBatch();
        $batch = $batches->findOrFail($batchId);
        $now = gmdate('Y-m-d H:i:s');
        $n = Database::execute('UPDATE transactions SET deleted_at = ?, deleted_by = ? WHERE household_id = ? AND import_batch_id = ? AND deleted_at IS NULL', [$now, (int) Auth::id(), (int) Auth::householdId(), $batchId]);
        $batches->update($batchId, ['status' => 'undone']);
        AuditService::log('import.undo', 'import_batch', $batchId, null, ['trashed' => $n, 'filename' => $batch['filename']]);
        return $n;
    }

    // --- utilidades ---

    public static function parseDate(string $value, string $format): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($d === false) {
            // tolera "01/09/2026 10:30"
            $d = DateTimeImmutable::createFromFormat('!' . $format . ' H:i', $value) ?: DateTimeImmutable::createFromFormat('!' . $format . ' H:i:s', $value);
        }
        return $d === false ? null : $d->format('Y-m-d');
    }

    /** "1.234,56" | "-1234.56" | "R$ 1.234,56" | "(12,00)" → float */
    public static function parseAmount(string $value): ?float
    {
        $v = trim(str_ireplace(['R$', ' ', "\u{a0}"], '', $value));
        if ($v === '') {
            return null;
        }
        $negative = str_starts_with($v, '-') || (str_starts_with($v, '(') && str_ends_with($v, ')')) || str_ends_with($v, '-') || str_ends_with($v, 'D');
        $v = trim($v, '()-+CD');
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = strrpos($v, ',') > strrpos($v, '.') ? str_replace(['.', ','], ['', '.'], $v) : str_replace(',', '', $v);
        } elseif (str_contains($v, ',')) {
            $v = str_replace(',', '.', $v);
        }
        if (!is_numeric($v)) {
            return null;
        }
        return $negative ? -abs((float) $v) : (float) $v;
    }

    private static function toUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        return mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }
}
