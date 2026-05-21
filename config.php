<?php
// config.php
define('CHATWOOT_URL', 'https://web.imperialseguranca.com');   // ← Mude para o URL do seu Chatwoot
define('ACCOUNT_ID', 3);                           // Geralmente 1 no self-hosted
define('INBOX_ID', 1);                             // Defina o inbox padrão para criar novas conversas quando necessário

// Gere um token em: Chatwoot → Perfil (canto inferior esquerdo) → Access Token
define('API_TOKEN', '1jZNCFg43dxq8H68a2cXyNtT');

// Segurança
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: *');
?>
