<?php
// app/Views/system/offline.php — exibida pelo service worker sem conexão
?>
<div class="text-center py-5 nc-maxw-sm mx-auto">
    <i class="bi bi-wifi-off display-3 text-body-secondary" aria-hidden="true"></i>
    <h1 class="h3 mt-3">Sem conexão</h1>
    <p class="text-body-secondary">Você está offline. Assim que a conexão voltar, recarregue a página. Nenhum dado foi perdido.</p>
    <button type="button" class="btn btn-primary" data-reload>Tentar novamente</button>
</div>
