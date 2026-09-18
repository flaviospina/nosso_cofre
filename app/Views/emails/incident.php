<?php /** @var string $name */ /** @var string $title */ /** @var string $description */ /** @var ?string $occurredAt */ /** @var string $detectedAt */ /** @var string $measures */ /** @var string $recommendations */ /** @var string $dpoName */ /** @var string $dpoEmail */ ?>
<p>Olá, <?= e($name) ?>.</p>
<p>Em cumprimento à Lei Geral de Proteção de Dados (art. 48), comunicamos um incidente de segurança que pode ter afetado seus dados pessoais.</p>
<p><strong>O que aconteceu:</strong> <?= e($title) ?></p>
<p style="white-space:pre-wrap;"><?= e($description) ?></p>
<p><strong>Quando:</strong> <?= $occurredAt ? 'ocorrido em ' . e(datetime_br($occurredAt)) . '; ' : '' ?>detectado em <?= e(datetime_br($detectedAt)) ?>.</p>
<p><strong>Medidas adotadas:</strong> <?= e($measures) ?></p>
<p><strong>O que recomendamos a você:</strong> <?= e($recommendations) ?></p>
<p>Dúvidas: fale com o encarregado de dados, <?= e($dpoName) ?>, em <a href="mailto:<?= e($dpoEmail) ?>"><?= e($dpoEmail) ?></a>. Você também pode registrar reclamação na Autoridade Nacional de Proteção de Dados (ANPD).</p>
