<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Encaminhar no Chatwoot</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .error { color: red; background: #ffe6e6; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .success { color: green; background: #e6ffe6; padding: 10px; border-radius: 5px; }
        .info { color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🔄 Encaminhar Mensagem</h2>
        
        <div id="status" class="info">Aguardando contexto do Chatwoot...</div>
        <div id="conv-info"></div>
        <div id="error-log" class="error" style="display:none;"></div>

        <h3>📎 Mensagens e Anexos</h3>
        <div id="messages-list">Carregando mensagens...</div>

        <h3>🔍 Buscar Destino</h3>
        <input type="text" id="search-input" placeholder="Nome, telefone ou email..." style="width:70%">
        <button onclick="searchContacts()">Buscar</button>

        <div id="target-info" style="margin-top:15px;"></div>
        <div id="contacts-list"></div>
        <div id="conversation-list"></div>

        <button onclick="forwardSelected()" class="btn-forward">✅ Encaminhar Selecionados</button>
    </div>

    <script src="assets/js/main.js"></script>
</body>
</html>