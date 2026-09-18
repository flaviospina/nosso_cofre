<?php
// app/Views/emails/alert.php — e-mail de aviso (qualquer tipo) e de resumo periódico
/** @var string $title */ /** @var string $body */ /** @var string $url */ /** @var string $color */ /** @var string $name */
?>
<p>Olá, <?= e($name) ?>.</p>
<div style="border-left:6px solid <?= e($color) ?>;padding:12px 16px;background:#f8fafc;border-radius:6px;margin:16px 0">
    <p style="margin:0 0 8px;font-size:17px;font-weight:600"><?= e($title) ?></p>
    <p style="margin:0;white-space:pre-line"><?= e($body) ?></p>
</div>
<p><a href="<?= e($url) ?>" style="display:inline-block;background:#0f766e;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px">Abrir no Nosso Cofre</a></p>
<p style="color:#64748b;font-size:12px">Você recebe este aviso porque ativou este tipo de notificação por e-mail. Para mudar canais, horários ou desligar tudo: Conta → Notificações.</p>
