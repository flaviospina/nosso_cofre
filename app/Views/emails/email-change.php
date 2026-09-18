<?php /** @var string $name */ /** @var string $url */ /** @var int $hours */ /** @var string $newEmail */ ?>
<p>Olá, <?= e($name) ?>.</p>
<p>Você pediu para usar <strong><?= e($newEmail) ?></strong> como e-mail da sua conta no Nosso Cofre. Confirme para concluir a troca:</p>
<p style="text-align:center;margin:28px 0;"><a href="<?= e($url) ?>" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;display:inline-block;">Confirmar novo e-mail</a></p>
<p style="font-size:13px;color:#6b7280;">O link vale por <?= (int) $hours ?> horas. Se não foi você, ignore: nada muda.</p>
