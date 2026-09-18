<?php
// app/Views/legal/document.php
/** @var array{version:string,effective:string,html:string,title:string} $doc */
?>
<article class="nc-maxw-md mx-auto nc-legal">
    <div class="text-body-secondary small mb-3">Versão <?= e($doc['version']) ?><?= $doc['effective'] !== '' ? ' · vigente desde ' . e($doc['effective']) : '' ?></div>
    <?= $doc['html'] ?>
</article>
