<?php
// app/Views/import/preview.php — mapeamento de colunas (CSV) e pré-visualização com duplicados e categorias sugeridas
/** @var string $key */
/** @var array<string,mixed> $meta */
/** @var array<string,mixed>|null $inspect */
/** @var array<string,mixed> $mapping */
/** @var array<string,mixed>|null $parsed */
/** @var array<string,string> $dateFormats */
/** @var array<int,array<string,mixed>> $accounts */
/** @var array<int,array<string,mixed>> $categories */
/** @var array<int,array<string,mixed>> $categoryMap */
/** @var array<int,array<string,mixed>> $members */
/** @var int $accountId */
/** @var int|null $me */
$isCsv = $meta['format'] === 'csv';
$colSelect = static function (string $name, string $label, ?int $selected, array $columns, string $help = ''): void { ?>
    <div class="col-6 col-md-4 col-lg-2">
        <label for="m_<?= e($name) ?>" class="form-label small mb-0"><?= e($label) ?></label>
        <select class="form-select form-select-sm" id="m_<?= e($name) ?>" name="<?= e($name) ?>">
            <option value="">—</option>
            <?php foreach ($columns as $i => $c): ?><option value="<?= (int) $i ?>" <?= $selected === (int) $i ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
        </select>
        <?php if ($help !== ''): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
    </div>
<?php };
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= e(route('import.index')) ?>">Importar extrato</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($meta['filename']) ?></li></ol></nav>
<h1 class="h3 mb-3">Conferir importação</h1>

<?php if ($isCsv && $inspect !== null): ?>
    <form method="post" action="<?= e(route('import.map', ['key' => $key])) ?>" class="card nc-card mb-3" novalidate><div class="card-body">
        <?= csrf_field() ?>
        <h2 class="h6"><span class="badge text-bg-secondary me-1">1</span>Diga qual coluna é o quê <span class="small text-body-secondary fw-normal">(delimitador detectado: "<?= e($inspect['delimiter'] === "\t" ? 'tab' : $inspect['delimiter']) ?>", <?= (int) $inspect['total'] ?> linha(s))</span></h2>
        <div class="table-responsive mb-2"><table class="table table-sm small mb-0">
            <thead><tr><?php foreach ($inspect['columns'] as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach (array_slice($inspect['sample'], 0, 3) as $row): ?><tr><?php foreach ($inspect['columns'] as $i => $c): ?><td class="text-truncate" data-maxw="14rem"><?= e((string) ($row[$i] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
        </table></div>
        <div class="row g-2">
            <?php $colSelect('date', 'Data', $mapping['date'], $inspect['columns']); ?>
            <?php $colSelect('description', 'Descrição', $mapping['description'], $inspect['columns']); ?>
            <?php $colSelect('amount', 'Valor', $mapping['amount'], $inspect['columns'], 'negativo = saída'); ?>
            <?php $colSelect('debit', 'Débito', $mapping['debit'], $inspect['columns'], 'se houver coluna própria'); ?>
            <?php $colSelect('credit', 'Crédito', $mapping['credit'], $inspect['columns']); ?>
            <?php $colSelect('type', 'Tipo (D/C)', $mapping['type'], $inspect['columns']); ?>
            <div class="col-6 col-md-4 col-lg-2">
                <label for="m_date_format" class="form-label small mb-0">Formato da data</label>
                <select class="form-select form-select-sm" id="m_date_format" name="date_format"><?php foreach ($dateFormats as $f => $l): ?><option value="<?= e($f) ?>" <?= $mapping['date_format'] === $f ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-6 col-md-4 col-lg-3 d-flex flex-column justify-content-end">
                <div class="form-check"><input class="form-check-input" type="checkbox" id="m_has_header" name="has_header" value="1" <?= $mapping['has_header'] ? 'checked' : '' ?>><label class="form-check-label small" for="m_has_header">Primeira linha é cabeçalho</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="m_invert" name="invert" value="1" <?= !empty($mapping['invert']) ? 'checked' : '' ?>><label class="form-check-label small" for="m_invert">Inverter sinal (fatura de cartão: positivo = gasto)</label></div>
            </div>
            <div class="col-12 col-lg-3 d-flex align-items-end justify-content-end"><button type="submit" class="btn btn-primary"><i class="bi bi-eye me-1" aria-hidden="true"></i>Pré-visualizar</button></div>
        </div>
    </div></form>
<?php endif; ?>

<?php if ($parsed !== null): $rows = $parsed['rows']; $dups = count(array_filter($rows, static fn(array $r): bool => (bool) $r['duplicate'])); ?>
    <?php if ($parsed['errors'] !== []): ?>
        <div class="alert alert-warning small"><strong><?= count($parsed['errors']) ?> linha(s) ignorada(s):</strong><ul class="mb-0 mt-1"><?php foreach (array_slice($parsed['errors'], 0, 8) as $err): ?><li><?= e($err) ?></li><?php endforeach; ?><?php if (count($parsed['errors']) > 8): ?><li>…</li><?php endif; ?></ul></div>
    <?php endif; ?>
    <?php if ($rows === []): ?>
        <div class="alert alert-info">Nenhuma linha válida encontrada. <?= $isCsv ? 'Confira o mapeamento das colunas e o formato da data.' : 'O arquivo OFX não tem transações.' ?></div>
    <?php else: ?>
        <form method="post" action="<?= e(route('import.confirm', ['key' => $key])) ?>" data-once novalidate class="card nc-card" data-import-form><div class="card-body">
            <?= csrf_field() ?>
            <?php foreach (['date', 'description', 'amount', 'debit', 'credit', 'type', 'date_format'] as $k): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) ($mapping[$k] ?? '')) ?>"><?php endforeach; ?>
            <input type="hidden" name="has_header" value="<?= !empty($mapping['has_header']) ? '1' : '0' ?>"><input type="hidden" name="invert" value="<?= !empty($mapping['invert']) ? '1' : '' ?>">
            <h2 class="h6"><span class="badge text-bg-secondary me-1"><?= $isCsv ? '2' : '1' ?></span>Confira as linhas <span class="small text-body-secondary fw-normal">(<?= count($rows) ?> encontrada(s)<?= $dups > 0 ? ', ' . $dups . ' já existente(s) desmarcada(s)' : '' ?><?= !empty($parsed['account']) ? ', conta ' . e($parsed['account']) : '' ?>)</span></h2>
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-4">
                    <label for="c_account" class="form-label small mb-0">Conta / cartão do extrato</label>
                    <select class="form-select form-select-sm<?= invalid_class('account_id') ?>" id="c_account" name="account_id" required>
                        <option value="">Escolha…</option>
                        <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) old('account_id', $accountId) === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
                    </select>
                    <?= field_error('account_id') ?>
                </div>
                <?php if (count($members) > 1): ?>
                    <div class="col-6 col-md-4">
                        <label for="c_resp" class="form-label small mb-0">Responsável</label>
                        <select class="form-select form-select-sm" id="c_resp" name="responsible_user_id"><option value="">Todos (da casa)</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['user_id'] ?>" <?= (int) $m['user_id'] === (int) $me ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="responsible_user_id" value="<?= (int) $me ?>">
                <?php endif; ?>
                <div class="col-6 col-md-4">
                    <label for="c_status" class="form-label small mb-0">Situação</label>
                    <select class="form-select form-select-sm" id="c_status" name="status"><option value="paid">Pagos / recebidos</option><option value="pending">Pendentes</option></select>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2 small">
                <span class="text-body-secondary"><span data-import-count><?= count($rows) - $dups ?></span> selecionada(s)</span>
                <button type="button" class="btn btn-sm btn-link p-0" data-import-select="all">todas</button>
                <button type="button" class="btn btn-sm btn-link p-0" data-import-select="none">nenhuma</button>
                <button type="button" class="btn btn-sm btn-link p-0" data-import-select="new">só as novas</button>
            </div>
            <div class="table-responsive"><table class="table table-sm align-middle nc-import-table">
                <thead><tr><th class="text-center"><span class="visually-hidden">Importar</span></th><th>Data</th><th>Descrição</th><th class="text-end">Valor</th><th>Categoria</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $i => $r): $sug = $r['suggested_category_id']; ?>
                    <tr class="<?= $r['duplicate'] ? 'table-warning' : '' ?>">
                        <td class="text-center"><input class="form-check-input" type="checkbox" name="rows[<?= $i ?>]" value="1" <?= $r['duplicate'] ? '' : 'checked' ?> data-import-row data-duplicate="<?= $r['duplicate'] ? '1' : '0' ?>" aria-label="Importar linha <?= $i + 1 ?>"></td>
                        <td class="text-nowrap small"><?= e(date_br($r['date'])) ?></td>
                        <td class="small"><?= e($r['description']) ?><?php if ($r['duplicate']): ?> <span class="badge text-bg-warning" title="Já existe um lançamento igual (mesma data, valor e descrição) ou mesmo FITID">duplicado</span><?php endif; ?></td>
                        <td class="text-end text-nowrap fw-semibold <?= $r['type'] === 'income' ? 'nc-income' : 'nc-expense' ?>"><?= $r['type'] === 'income' ? '+' : '−' ?><?= e(money($r['amount'])) ?></td>
                        <td>
                            <select class="form-select form-select-sm" name="category[<?= $i ?>]" aria-label="Categoria da linha <?= $i + 1 ?>">
                                <option value="">Sem categoria</option>
                                <?php foreach ($categories as $c): if ($c['kind'] !== $r['type']) { continue; } ?><option value="<?= (int) $c['id'] ?>" <?= $sug !== null && (int) $sug === (int) $c['id'] ? 'selected' : '' ?>><?= $c['depth'] === 1 ? '— ' : '' ?><?= e($c['name']) ?></option><?php endforeach; ?>
                            </select>
                            <?php if ($sug !== null): ?><div class="form-text py-0 my-0"><i class="bi bi-magic" aria-hidden="true"></i> sugerida</div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="d-flex justify-content-between mt-3">
                <a class="btn btn-outline-secondary" href="<?= e(route('import.index')) ?>">Cancelar</a>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Importar selecionadas</button>
            </div>
        </div></form>
    <?php endif; ?>
<?php endif; ?>
