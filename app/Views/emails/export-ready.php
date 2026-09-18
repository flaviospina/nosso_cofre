<?php /** @var string $name */ /** @var string $url */ /** @var int $hours */ ?>
<p>Olá, <?= e($name) ?>.</p>
<p>A exportação dos seus dados do Nosso Cofre está pronta (JSON + CSV em um arquivo ZIP).</p>
<p style="text-align:center;margin:28px 0;"><a href="<?= e($url) ?>" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;display:inline-block;">Baixar meus dados</a></p>
<p style="font-size:13px;color:#6b7280;">O link vale por <?= (int) $hours ?> horas e só funciona com você conectado à sua conta. Depois disso o arquivo é apagado do servidor.</p>
