<?php
// app/Views/invitation/invalid.php
ob_start(); ?>
<p>Este convite não existe, já foi usado, foi cancelado ou expirou (os convites valem por 7 dias).</p>
<p class="mb-0">Peça um novo convite a quem administra o lar.</p>
<?php $body = ob_get_clean();
echo \App\Core\View::partial('auth-card', ['heading' => 'Convite inválido', 'lead' => null, 'body' => $body]);
