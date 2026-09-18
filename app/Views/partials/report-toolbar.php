<?php
// app/Views/partials/report-toolbar.php — cabeçalho dos relatórios com filtros e exportação (CSV/PDF)
/** @var string $title */
/** @var string $subtitle */
/** @var array<string,mixed> $query */
/** @var string $routeName */
$exportQuery = static fn(string $format): array => $query + ['formato' => $format];
?>
<nav aria-label="breadcrumb" class="nc-no-print"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= e(route('reports.index')) ?>">Relatórios</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($title) ?></li></ol></nav>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div><h1 class="h3 mb-0"><?= e($title) ?></h1><div class="text-body-secondary"><?= e($subtitle) ?></div></div>
    <div class="d-flex gap-2 nc-no-print">
        <a class="btn btn-outline-secondary" href="<?= e(route($routeName, [], $exportQuery('csv'))) ?>"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>
        <a class="btn btn-outline-secondary" href="<?= e(route($routeName, [], $exportQuery('pdf'))) ?>"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</a>
    </div>
</div>
