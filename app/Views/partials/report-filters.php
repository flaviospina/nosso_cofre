<?php
// app/Views/partials/report-filters.php — filtros de mês/ano e membro (GET) dos relatórios
/** @var string $routeName */
/** @var array<string,mixed> $query */
/** @var array<int,array<string,mixed>> $members */
/** @var bool $isFamily */
/** @var string $period 'month' | 'year' | 'none' */
/** @var int|null $memberId */
/** @var string $month */
?>
<form method="get" action="<?= e(route($routeName)) ?>" class="d-flex flex-wrap align-items-end gap-2 mb-3 nc-no-print">
    <?php foreach ($query as $k => $v): if (in_array($k, ['mes', 'ano', 'membro'], true)) { continue; } ?><input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
    <?php if ($period === 'month'): ?>
        <div><label for="f_mes" class="form-label small mb-0">Mês</label><input type="month" class="form-control form-control-sm" id="f_mes" name="mes" value="<?= e($month) ?>"></div>
    <?php elseif ($period === 'year'): ?>
        <div><label for="f_ano" class="form-label small mb-0">Ano</label><input type="number" min="2000" max="2100" class="form-control form-control-sm" id="f_ano" name="ano" value="<?= e((string) ($query['ano'] ?? date('Y'))) ?>"></div>
    <?php endif; ?>
    <?php if ($isFamily && isset($memberId)): ?>
        <div><label for="f_membro" class="form-label small mb-0">Membro</label><select class="form-select form-select-sm" id="f_membro" name="membro"><option value="">Lar inteiro</option><?php foreach ($members as $mb): ?><option value="<?= (int) $mb['user_id'] ?>" <?= $memberId === (int) $mb['user_id'] ? 'selected' : '' ?>><?= e($mb['name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <button type="submit" class="btn btn-sm btn-primary">Aplicar</button>
</form>
