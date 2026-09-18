<?php
// app/Views/emails/layout.php — moldura dos e-mails (HTML simples, compatível com clientes de e-mail)
/** @var string $subject */
/** @var string $content */
$appName = (string) config('app.name', 'Nosso Cofre');
$appUrl = (string) config('app.url', '');
?>
<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><title><?= e($subject) ?></title></head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6f8;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">
<tr><td style="background:#0f766e;color:#ffffff;padding:16px 24px;font-size:18px;font-weight:bold;"><?= e($appName) ?></td></tr>
<tr><td style="padding:24px;font-size:15px;line-height:1.5;"><?= $content ?></td></tr>
<tr><td style="padding:16px 24px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;">
Este e-mail foi enviado automaticamente pelo <?= e($appName) ?><?= $appUrl !== '' ? ' (' . e($appUrl) . ')' : '' ?>. Se você não esperava esta mensagem, pode ignorá-la.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
