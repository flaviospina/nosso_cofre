<?php
// app/Views/import/index.php — envio de extrato CSV/OFX e histórico de lotes (com desfazer)
/** @var array<int,array<string,mixed>> $batches */
/** @var array<int,array<string,mixed>> $accounts */
/** @var bool $canWrite */
$statusLabel = ['pending' => 'Em andamento', 'done' => 'Concluído', 'failed' => 'Falhou', 'undone' => 'Desfeito'];
?>
<div class="nc-maxw-md mx-auto">
    <nav aria-label="breadcrumb"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= e(route('transactions.index')) ?>">Lançamentos</a></li><li class="breadcrumb-item active" aria-current="page">Importar extrato</li></ol></nav>
    <h1 class="h3 mb-2">Importar extrato</h1>
    <p class="text-body-secondary">Envie o extrato do banco ou do cartão em <strong>CSV</strong> ou <strong>OFX</strong> (até 2 MB). Antes de gravar, você confere cada linha: duplicados vêm desmarcados e as categorias são sugeridas pelo que você já lançou.</p>

    <?php if ($canWrite): ?>
        <form method="post" action="<?= e(route('import.upload')) ?>" enctype="multipart/form-data" data-once novalidate class="card nc-card mb-4"><div class="card-body">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-12 col-md-7">
                    <label for="file" class="form-label">Arquivo</label>
                    <input type="file" class="form-control<?= invalid_class('file') ?>" id="file" name="file" accept=".csv,.txt,.ofx,.qfx,text/csv,application/x-ofx" required>
                    <?= field_error('file') ?>
                </div>
                <div class="col-12 col-md-5">
                    <label for="account_id" class="form-label">Conta do extrato</label>
                    <select class="form-select" id="account_id" name="account_id">
                        <option value="">Escolher depois</option>
                        <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-3"><button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Enviar e conferir</button></div>
        </div></form>
    <?php endif; ?>

    <h2 class="h5">Lotes importados</h2>
    <?php if ($batches === []): ?>
        <p class="text-body-secondary">Nenhuma importação ainda.</p>
    <?php else: ?>
        <div class="card nc-card"><ul class="list-group list-group-flush">
            <?php foreach ($batches as $b): ?>
                <li class="list-group-item d-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-<?= $b['format'] === 'ofx' ? 'code' : 'spreadsheet' ?> fs-4 text-body-secondary" aria-hidden="true"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="text-truncate"><?= e($b['filename']) ?> <span class="badge <?= $b['status'] === 'done' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= e($statusLabel[$b['status']] ?? $b['status']) ?></span></div>
                        <div class="small text-body-secondary"><?= e(datetime_br($b['created_at'])) ?> · <?= (int) $b['rows_imported'] ?> importado(s) de <?= (int) $b['rows_total'] ?><?= (int) $b['rows_duplicated'] > 0 ? ' · ' . (int) $b['rows_duplicated'] . ' duplicado(s)' : '' ?></div>
                    </div>
                    <?php if ($canWrite && $b['status'] === 'done' && (int) $b['rows_imported'] > 0): ?>
                        <form method="post" action="<?= e(route('import.undo', ['id' => $b['id']])) ?>" data-confirm="Desfazer esta importação? Os lançamentos vão para a lixeira."><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Desfazer</button></form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
</div>
