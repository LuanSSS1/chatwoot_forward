<?php
require_once __DIR__ . "/../core/Env.php";

class Chatwoot
{
    protected string $apiBaseUrl;
    protected string $apiToken;
    protected int $accountId;
    protected int $inboxId;
    private static string $logFile = __DIR__ . "/logs/chatwoot_debug.log";

    private static function debug(string $mensagem, ?array $dados = null): void
    {
        if (false){
        $timestamp = date('Y-m-d H:i:s');
        $msg = "[{$timestamp}] {$mensagem}";
        if ($dados !== null) {
            $msg .= ' | ' . json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        $msg .= "\n";

        $dir = dirname(self::$logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(self::$logFile, $msg, FILE_APPEND);
        }
    }

    protected int $agentUserId;

    public function __construct()
    {
        Env::load(dirname(__DIR__));
        $this->apiBaseUrl = rtrim((string)Env::get('CHATWOOT_API_BASE_URL', ''), '/');
        $this->apiToken = trim((string)Env::get('CHATWOOT_API_TOKEN', ''));
        $this->accountId = (int)Env::get('CHATWOOT_ACCOUNT_ID', '0');
        $this->inboxId = (int)Env::get('CHATWOOT_INBOX_ID', '0');
        $this->agentUserId = (int)Env::get('CHATWOOT_AGENT_USER_ID', '0');
    }

    public function isConfigured(): bool
    {
        return $this->apiBaseUrl !== '' && $this->apiToken !== '' && $this->accountId > 0 && $this->inboxId > 0 && $this->agentUserId > 0;
    }

    protected function buildUrl(string $path): string
    {
        return $this->apiBaseUrl . '/api/v1/accounts/' . $this->accountId . $path;
    }

    protected function getHeaders(): array
    {
        return [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiToken,
            'api_access_token: ' . $this->apiToken,
        ];
    }

    protected function request(string $method, string $path, ?array $body = null): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Chatwoot não está configurado no ambiente.');
        }

        $url = $this->buildUrl($path);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders());
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        self::debug('Chatwoot request', [
            'method' => $method,
            'url' => $url,
            'body' => $body,
            'http_code' => $httpCode,
            'raw_response' => $response,
            'curl_error' => $curlError,
        ]);

        if ($response === false) {
            throw new RuntimeException('Erro de conexão com Chatwoot: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            self::debug('Chatwoot invalid JSON response', ['raw' => $response, 'json_error' => json_last_error_msg()]);
            throw new RuntimeException('Resposta inválida do Chatwoot: ' . json_last_error_msg());
        }

        if ($httpCode >= 400) {
            $message = $decoded['message'] ?? $decoded['errors'] ?? $response;
            if (is_array($message)) {
                $message = implode('; ', array_map('strval', $message));
            }
            self::debug('Chatwoot error response', ['http_code' => $httpCode, 'message' => $message, 'decoded' => $decoded]);
            throw new RuntimeException('Chatwoot retornou erro ' . $httpCode . ': ' . $message);
        }

        return $decoded;
    }

    /**
     * Normaliza telefone para formato aceito pelo Evolution API + WhatsApp Brasil
     * Exemplo: +5569992655577 (com o 9)
     */
    protected function normalizePhoneNumber(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (strlen($digits) < 10) {
            return null;
        }

        // Remove 55 se já vier no início
        if (str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        // Ajusta para ter exatamente 13 dígitos (DDD + 9 + 8 dígitos)
        if (strlen($digits) === 11) {                    // já com 9
            return '+55' . $digits;
        } elseif (strlen($digits) === 10) {              // sem o 9
            $ddd = substr($digits, 0, 2);
            $numero = substr($digits, 2);
            return '+55' . $ddd . '9' . $numero;
        } elseif (strlen($digits) === 12) {
            return '+55' . substr($digits, 2);           // 55 + DDD + 8 dígitos (raro)
        } elseif (strlen($digits) === 13) {
            return '+55' . $digits;
        }

        return '+55' . $digits; // fallback
    }

    protected function extractContactsFromSearchResponse(array $result): ?array
    {
        if (isset($result['payload']) && is_array($result['payload'])) {
            if (!empty($result['payload']) && array_values($result['payload']) === $result['payload']) {
                return $result['payload'][0] ?? null;
            }

            if (isset($result['payload']['contacts']) && is_array($result['payload']['contacts'])) {
                return $result['payload']['contacts'][0] ?? null;
            }
        }

        if (isset($result['contacts']) && is_array($result['contacts']) && count($result['contacts']) > 0) {
            return $result['contacts'][0];
        }

        if (!empty($result) && array_values($result) === $result) {
            return $result[0] ?? null;
        }

        return null;
    }

    protected function contactMatchesQuery(array $contact, string $query): bool
    {
        $normalizedQuery = trim(strtolower($query));
        $phoneDigits = preg_replace('/\D+/', '', $query);

        if ($phoneDigits !== '') {
            $contactPhone = isset($contact['phone_number']) ? preg_replace('/\D+/', '', (string)$contact['phone_number']) : '';
            if ($contactPhone !== '' && str_ends_with($contactPhone, $phoneDigits)) {
                return true;
            }
        }

        if (filter_var($query, FILTER_VALIDATE_EMAIL)) {
            $contactEmail = isset($contact['email']) ? strtolower(trim((string)$contact['email'])) : '';
            if ($contactEmail !== '' && $contactEmail === $normalizedQuery) {
                return true;
            }
        }

        if (isset($contact['identifier']) && strtolower(trim((string)$contact['identifier'])) === $normalizedQuery) {
            return true;
        }

        if (isset($contact['name']) && stripos((string)$contact['name'], $query) !== false) {
            return true;
        }

        return false;
    }

    public function searchContact(string $query): ?array
{
    if (empty(trim($query))) {
        return null;
    }

    $query = trim($query);
    $paths = [];

    // Buscas prioritárias (mais confiáveis)
    if (filter_var($query, FILTER_VALIDATE_EMAIL)) {
        $paths[] = "/contacts/search?q=" . urlencode($query);
        $paths[] = "/contacts?email=" . urlencode($query);
    }

    // Telefone (várias variações)
    $normalized = $this->normalizePhoneNumber($query);
    if ($normalized) {
        $phoneOnly = preg_replace('/\D+/', '', $normalized);
        $paths[] = "/contacts/search?q=" . urlencode($normalized);
        $paths[] = "/contacts/search?q=" . urlencode($phoneOnly);
        $paths[] = "/contacts?phone_number=" . urlencode($normalized);
        $paths[] = "/contacts?phone=" . urlencode($phoneOnly);
    }

    // Identifier (seu padrão inscricao-XXXX)
    if (str_starts_with($query, 'inscricao-')) {
        $paths[] = "/contacts/search?q=" . urlencode($query);
        $paths[] = "/contacts?identifier=" . urlencode($query);
    }

    // Busca genérica como fallback
    $paths[] = "/contacts/search?q=" . urlencode($query);

    foreach ($paths as $path) {
        try {
            $result = $this->request('GET', $path);
            $contact = $this->extractContactsFromSearchResponse($result);

            if ($contact && isset($contact['id']) && $this->contactMatchesQuery($contact, $query)) {
                self::debug('Chatwoot contact found', ['query' => $query, 'contact_id' => $contact['id']]);
                return $contact;
            }
        } catch (RuntimeException $e) {
            self::debug('Search failed (ignored)', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    return null;
}

    public function getContactConversations(int $contactId): array
    {
        try {
            $result = $this->request('GET', '/contacts/' . $contactId . '/conversations');
            if (isset($result['payload']) && is_array($result['payload'])) {
                return $result['payload'];
            }
            if (isset($result['conversations']) && is_array($result['conversations'])) {
                return $result['conversations'];
            }
            return [];
        } catch (RuntimeException $exception) {
            return [];
        }
    }

    public function getConversationMessages(int $conversationId): array
    {
        try {
            $result = $this->request('GET', '/conversations/' . $conversationId . '/messages');
            if (isset($result['payload']) && is_array($result['payload'])) {
                return $result['payload'];
            }
            if (isset($result['messages']) && is_array($result['messages'])) {
                return $result['messages'];
            }
            return [];
        } catch (RuntimeException $exception) {
            return [];
        }
    }

    public function createContact(array $payload): array
{
    if (!isset($payload['inbox_id'])) {
        $payload['inbox_id'] = $this->inboxId;
    }

    $attempts = [
        '/contacts',
        '/inboxes/' . $this->inboxId . '/contacts'
    ];

    foreach ($attempts as $path) {
        try {
            $result = $this->request('POST', $path, $payload);
            return $result['payload'] ?? $result;
        } catch (RuntimeException $exception) {
            $msg = $exception->getMessage();

            // Se for erro de duplicidade → tenta recuperar o contato existente
            if (stripos($msg, 'already been taken') !== false || 
                stripos($msg, 'duplicate') !== false || 
                stripos($msg, 'has already been taken') !== false) {

                self::debug('Duplicate detected, trying to recover existing contact', ['payload' => $payload]);

                $searchKeys = array_filter([
                    $payload['email'] ?? null,
                    $payload['phone_number'] ?? null,
                    $payload['identifier'] ?? null,
                    isset($payload['phone_number']) ? preg_replace('/\D+/', '', $payload['phone_number']) : null
                ]);

                foreach ($searchKeys as $key) {
                    if (empty($key)) continue;

                    $existing = $this->searchContact($key);
                    if ($existing && isset($existing['id'])) {
                        self::debug('Recovered existing contact', ['id' => $existing['id']]);
                        return $existing;
                    }
                }
            }

            // Se for 404 no primeiro path, tenta o fallback
            if ($path === $attempts[0] && (stripos($msg, '404') !== false || stripos($msg, 'not found') !== false)) {
                continue;
            }

            throw $exception;
        }
    }

    throw new RuntimeException('Não foi possível criar ou recuperar o contato no Chatwoot.');
}

    public function createConversation(int $contactId, string $sourceId): array
    {
        $payload = [
            'inbox_id' => $this->inboxId,
            'contact_id' => $contactId,
            'source_id' => $sourceId,
            'status' => 'open'
        ];

        try {
            $result = $this->request('POST', '/conversations', $payload);
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (stripos($message, '404') !== false || stripos($message, 'Resource could not be found') !== false) {
                self::debug('Chatwoot createConversation fallback to inbox route', ['contact_id' => $contactId, 'source_id' => $sourceId, 'inbox_id' => $this->inboxId, 'error' => $message]);
                $result = $this->request('POST', '/inboxes/' . $this->inboxId . '/conversations', $payload);
            } else {
                throw $exception;
            }
        }

        return isset($result['payload']) && is_array($result['payload']) ? $result['payload'] : $result;
    }

    public function sendMessage(int $conversationId, string $message): array
    {
        $payload = [
            'content' => $message,
            'message_type' => 'outgoing',
            'private' => false,
        ];

        $result = $this->request('POST', '/conversations/' . $conversationId . '/messages', $payload);
        return isset($result['payload']) && is_array($result['payload']) ? $result['payload'] : $result;
    }

    public function assignConversation(int $conversationId, int $userId): array
    {
        $payload = ['assignee_id' => $userId];
        try {
            $result = $this->request('POST', '/conversations/' . $conversationId . '/assignments', $payload);
            return isset($result['payload']) && is_array($result['payload']) ? $result['payload'] : $result;
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (stripos($message, '404') !== false || stripos($message, 'Not Found') !== false) {
                self::debug('Chatwoot assignConversation ignored unsupported endpoint', ['conversation_id' => $conversationId, 'user_id' => $userId, 'error' => $message]);
                return [];
            }
            throw $exception;
        }
    }

    public function resolveConversation(int $conversationId): array
    {
        $payload = ['status' => 'resolved'];

        try {
            $result = $this->request('POST', '/conversations/' . $conversationId . '/toggle_status', $payload);
            return isset($result['payload']) && is_array($result['payload']) ? $result['payload'] : $result;
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (stripos($message, '404') !== false || stripos($message, 'Not Found') !== false) {
                self::debug('Chatwoot resolveConversation ignored unsupported endpoint', ['conversation_id' => $conversationId, 'error' => $message]);
                return [];
            }
            throw $exception;
        }
    }

  /**
     * Cria ou recupera contato - Versão simplificada e robusta
     */
    public function findOrCreateContact(array $inscricao): ?array
    {
        $name  = trim((string)($inscricao['nome'] ?? ''));
        $email = trim((string)($inscricao['email'] ?? ''));
        $phone = trim((string)($inscricao['telefone'] ?? ''));

        //$normalizedPhone = "+55" . preg_replace('/\D+/', '', $phone);

        $normalizedPhone = $this->normalizePhoneNumber($phone);

        if (empty($normalizedPhone) && empty($email)) {
            throw new RuntimeException('Telefone ou email é obrigatório para criar contato no Chatwoot.');
        }

        // Tenta buscar primeiro
        $searchKeys = array_filter([$normalizedPhone, $email, 'inscricao-' . ($inscricao['id'] ?? '')]);

        foreach ($searchKeys as $key) {
            $contact = $this->searchContact($key);
            if ($contact && !empty($contact['id'])) {
                self::debug('Contato encontrado', ['id' => $contact['id'], 'phone_number' => $contact['phone_number'] ?? null]);
                return $contact;
            }
        }

        // Cria novo contato
        $identifier = 'inscricao-' . ($inscricao['id'] ?? uniqid('inscricao-', true));
        //$identifier = $phone . "@lid";
        //$payload = [
       //     'inbox_id'   => $this->inboxId,
       //     'name'       => $name ?: 'Cliente',
       //     'identifier' => $identifier,
       // ];

         $payload = [
            'inbox_id'   => $this->inboxId,
            'name'       => $name ?: 'Cliente'
        ];

        //if (!empty($email)) {
       //     $payload['email'] = $email;
       // }
        if ($normalizedPhone) {
            $payload['phone_number'] = $normalizedPhone;   // ← Este é o ponto crítico
        }

        self::debug('Criando contato no Chatwoot', [
            'original_phone' => $phone,
            'normalized_phone' => $normalizedPhone,
            'identifier' => $identifier
        ]);

        try {
            $result = $this->request('POST', '/contacts', $payload);
            return $result['payload'] ?? $result;
        } catch (RuntimeException $e) {
            // Em caso de duplicidade, tenta buscar novamente
            if (stripos($e->getMessage(), 'already been taken') !== false) {
                foreach ($searchKeys as $key) {
                    $contact = $this->searchContact($key);
                    if ($contact && !empty($contact['id'])) {
                        return $contact;
                    }
                }
            }
            throw $e;
        }
    }

    public function findOrCreateConversation(int $contactId, string $sourceIdBase): ?array
{
    // 1. Busca conversas existentes do contato
    $conversations = $this->getContactConversations($contactId);

    if (!empty($conversations)) {
        // Prioriza conversa aberta (status 'open' ou 'pending')
        foreach ($conversations as $conv) {
            if (isset($conv['id']) && in_array(($conv['status'] ?? ''), ['open', 'pending'])) {
                self::debug('Reusing existing open conversation', ['conversation_id' => $conv['id']]);
                return $conv;
            }
        }

        // Se não tem aberta, pega a mais recente
        $conversation = $conversations[0];
        self::debug('Reusing latest conversation', ['conversation_id' => $conversation['id']]);
        return $conversation;
    }

    // 2. Não encontrou conversa → cria uma nova com source_id ÚNICO
    $uniqueSourceId = $sourceIdBase . '-' . time();   // ou uniqid('-', true)

    $payload = [
        'inbox_id'   => $this->inboxId,
        'contact_id' => $contactId,
        'source_id'  => $uniqueSourceId,
        'status'     => 'open'
    ];

    try {
        $result = $this->request('POST', '/conversations', $payload);
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();

        // Fallback para rota por inbox (algumas versões do Chatwoot precisam disso)
        if (stripos($message, '404') !== false || stripos($message, 'not found') !== false) {
            self::debug('createConversation fallback to inbox route');
            $result = $this->request('POST', '/inboxes/' . $this->inboxId . '/conversations', $payload);
        } else {
            throw $exception;
        }
    }

    return isset($result['payload']) && is_array($result['payload']) ? $result['payload'] : $result;
}

    public function sendMessageToConversation(int $conversationId, string $message): array
    {
        $payload = [
            'content' => $message,
            'message_type' => 'outgoing',
            'private' => false,
        ];

        $result = $this->request('POST', '/conversations/' . $conversationId . '/messages', $payload);
        if (isset($result['payload']) && is_array($result['payload'])) {
            return $result['payload'];
        }
        return $result;
    }

    public function sendStatusMessage(array $inscricao, ?string $paymentUrl = null, ?array $evento = null): array
    {
        $contact = $this->findOrCreateContact($inscricao);
        //pausa
        sleep(3);      
        
        if (!$contact || empty($contact['id'])) {
           $contact = $this->findOrCreateContact($inscricao);
        }

        if (!$contact || empty($contact['id'])) {
            throw new RuntimeException('O contato foi criado, Tente clicar novamente para enviar a mensagem.');
        }

        $message = '';
        if ((int)($inscricao['pago'] ?? 0) === 1) {
            $message = 'Paz do Senhor, ' . ($inscricao['nome'] ?? '') . '! 😊. Informamos que seu pagamento foi confirmado e sua inscrição no evento ' . ($evento['nome'] ?? '') . ' da Igreja Assembleia de Deus foi realizada com sucesso. Obrigado por participar!';
        } else {
            $link = trim((string)$paymentUrl);
            if ($link === '') {
                $link = trim((string)($inscricao['url'] ?? ''));
            }
$message = 'Paz do Senhor, ' . ($inscricao['nome'] ?? '') . '! 😊 Seu cadastro no evento *' . ($evento['nome'] ?? 'Evento') . '* da Igreja Assembleia de Deus foi realizado com sucesso.
No entanto, ainda não identificamos o pagamento da inscrição.
Quaisquer dúvidas, basta entrar em contato conosco, respondendo esta mensagem.
É possível concluir o pagamento através do link: ' . ($link ?: 'link de pagamento indisponível');
        }

       $sourceIdBase = 'inscricao-' . ($inscricao['id'] ?? uniqid('inscricao-', true));

// Usa o método melhorado
$conversation = $this->findOrCreateConversation((int)$contact['id'], $sourceIdBase);

if (!$conversation || empty($conversation['id'])) {
    throw new RuntimeException('Não foi possível criar ou recuperar a conversa no Chatwoot.');
}

$conversationId = (int)$conversation['id'];
        $conversationId = $conversation['id'] ?? null;
        if (!$conversationId) {
            throw new RuntimeException('Não foi possível criar a conversa no Chatwoot.');
        }


        $this->assignConversation((int)$conversationId, $this->agentUserId);
        sleep(1);
        $messageResult = $this->sendMessage((int)$conversationId, $message);        
        sleep(1);
        $this->resolveConversation((int)$conversationId);

        return $messageResult;
    }
}
s