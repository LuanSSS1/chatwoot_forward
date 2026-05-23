Sistema de encaminhamento de mensagens, imagens e arquivos na aba de aplicativos do chatwoot.

Message, image, and file forwarding system in the Chatwoot applications tab.


<?php
// config.php -> Salvar na página inicial o arquivo de configurações 
define('CHATWOOT_URL', 'https://chatwoot.com');   // ← Mude para o URL do seu Chatwoot
define('ACCOUNT_ID', 1);                           // Geralmente 1 no self-hosted
define('INBOX_ID', 1);                             // Defina o inbox padrão para criar novas conversas quando necessário

// Gere um token em: Chatwoot → Perfil (canto inferior esquerdo) → Access Token
define('API_TOKEN', '');

// Segurança
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: *');

<img width="1381" height="983" alt="image" src="https://github.com/user-attachments/assets/d19ec6cc-523a-4c58-9735-5fcf1750ea2b" />
