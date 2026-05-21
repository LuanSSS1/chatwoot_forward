<?php
require_once '../config.php';

function jsonResponse($data, int $statusCode = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function errorResponse(string $message, int $statusCode = 400): void {
    jsonResponse(['error' => $message], $statusCode);
}

function buildMultipartFields(array $data, string $prefix = ''): array {
    $fields = [];
    foreach ($data as $key => $value) {
        if ($prefix === '') {
            $fieldName = $key;
            if (substr($key, -2) === '[]') {
                $fieldName = substr($key, 0, -2);
            }
        } else {
            $fieldName = $prefix . '[' . $key . ']';
        }

        if (is_array($value)) {
            $fields = array_merge($fields, buildMultipartFields($value, $fieldName));
            continue;
        }

        $fields[$fieldName] = $value;
    }
    return $fields;
}

function cwRequest(string $endpoint, string $method = 'GET', $data = null, bool $isMultipart = false): array {
    $url = rtrim(CHATWOOT_URL, '/') . '/api/v1/accounts/' . ACCOUNT_ID . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $headers = ['api_access_token: ' . API_TOKEN];
    if (!$isMultipart) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($isMultipart && is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, buildMultipartFields($data));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['code' => $httpCode ?: 0, 'body' => ['error' => 'Falha de comunicação com Chatwoot', 'curl_error' => $curlError]];
    }

    $decoded = json_decode($response, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        $decoded = ['error' => 'Invalid JSON response from Chatwoot', 'raw' => $response, 'curl_error' => $curlError];
    }

    return ['code' => $httpCode, 'body' => $decoded];
}

function getPayload(array $body): array {
    if (isset($body['payload']) && is_array($body['payload'])) {
        return $body['payload'];
    }
    if (isset($body['contacts']) && is_array($body['contacts'])) {
        return $body['contacts'];
    }
    if (isset($body['messages']) && is_array($body['messages'])) {
        return $body['messages'];
    }
    return $body;
}

function buildForwardText(array $messages, int $sourceConversationId): string {
    $lines = ["🔁 Encaminhado da conversa #{$sourceConversationId}", ''];
    foreach ($messages as $message) {
        $author = isset($message['message_type']) && $message['message_type'] === 1 ? 'Operador' : 'Cliente';
        $content = trim($message['content'] ?? $message['body'] ?? '');
        if ($content === '') {
            $content = '[Mensagem sem texto]';
        }
        $lines[] = "[{$author}] " . str_replace("\n", ' ', $content);
        if (!empty($message['attachments']) && is_array($message['attachments'])) {
            foreach ($message['attachments'] as $attachment) {
                $name = $attachment['file_name'] ?? $attachment['name'] ?? $attachment['filename'] ?? $attachment['title'] ?? ($attachment['id'] ?? 'anexo');
                $lines[] = "Anexo: {$name} (arquivo incluído)";
            }
        }
        $lines[] = '---';
    }
    return trim(implode("\n", $lines));
}

function downloadRemoteFile(string $url): ?string {
    if (trim($url) === '') {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($data === false || $httpCode >= 400) {
        return null;
    }

    $pathInfo = pathinfo(parse_url($url, PHP_URL_PATH) ?? '');
    $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
    $tmpFile = tempnam(sys_get_temp_dir(), 'cwatt_');
    if ($tmpFile === false) {
        return null;
    }

    $tmpPath = $tmpFile . $extension;
    if (!file_put_contents($tmpPath, $data)) {
        @unlink($tmpFile);
        return null;
    }

    if ($extension !== '') {
        @unlink($tmpFile);
    }

    return $tmpPath;
}

function prepareAttachments(array $messages): array {
    $attachments = [];
    foreach ($messages as $message) {
        if (empty($message['attachments']) || !is_array($message['attachments'])) {
            continue;
        }
        foreach ($message['attachments'] as $attachment) {
            $url = $attachment['file_url'] ?? $attachment['data_url'] ?? $attachment['url'] ?? $attachment['content'] ?? $attachment['thumb_url'] ?? '';
            $tmpPath = downloadRemoteFile($url);
            if ($tmpPath) {
                $name = $attachment['file_name'] ?? $attachment['name'] ?? $attachment['filename'] ?? $attachment['title'] ?? basename($tmpPath);
                $mime = mime_content_type($tmpPath) ?: 'application/octet-stream';
                $attachments[] = ['path' => $tmpPath, 'name' => $name, 'mime' => $mime];
            }
        }
    }
    return $attachments;
}

function getConversationTimestamp(array $conversation): int {
    $fields = ['updated_at', 'last_activity_at', 'created_at', 'timestamp'];
    foreach ($fields as $field) {
        if (!empty($conversation[$field])) {
            if (is_numeric($conversation[$field])) {
                return (int) $conversation[$field];
            }
            $ts = strtotime($conversation[$field]);
            if ($ts !== false) {
                return $ts;
            }
        }
    }
    return 0;
}

function getLatestConversation(array $conversations): array {
    $best = [];
    $bestTimestamp = 0;
    foreach ($conversations as $conversation) {
        $timestamp = getConversationTimestamp((array)$conversation);
        if ($timestamp > $bestTimestamp) {
            $bestTimestamp = $timestamp;
            $best = (array) $conversation;
        }
    }
    return $best;
}

function getRequestArray(string $field): array {
    $value = $_REQUEST[$field] ?? null;
    if ($value === null) {
        return [];
    }
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

$action = $_REQUEST['action'] ?? '';

if ($action === 'get_messages') {
    $conversationId = (int)($_REQUEST['conversation_id'] ?? 0);
    if ($conversationId <= 0) {
        errorResponse('conversation_id inválido', 400);
    }

    $result = cwRequest("/conversations/{$conversationId}/messages");
    if ($result['code'] >= 400) {
        errorResponse($result['body']['error'] ?? ($result['body']['message'] ?? 'Erro ao buscar mensagens'), $result['code']);
    }
    jsonResponse($result['body']);
}

if ($action === 'search_contacts') {
    $q = trim((string)($_REQUEST['q'] ?? ''));
    if ($q === '') {
        errorResponse('Parâmetro q é obrigatório para buscar contatos', 400);
    }

    $result = cwRequest('/contacts/search?q=' . urlencode($q));
    if ($result['code'] >= 400) {
        errorResponse($result['body']['error'] ?? ($result['body']['message'] ?? 'Erro ao buscar contatos'), $result['code']);
    }

    $payload = getPayload($result['body']);
    jsonResponse(['contacts' => ensureArray($payload)]);
}

if ($action === 'get_contact_conversations') {
    $contactId = (int)($_REQUEST['contact_id'] ?? 0);
    if ($contactId <= 0) {
        errorResponse('contact_id inválido', 400);
    }

    $result = cwRequest("/contacts/{$contactId}/conversations");
    if ($result['code'] >= 400) {
        errorResponse($result['body']['error'] ?? ($result['body']['message'] ?? 'Erro ao buscar conversas do contato'), $result['code']);
    }

    $payload = getPayload($result['body']);
    jsonResponse(['conversations' => ensureArray($payload)]);
}

if ($action === 'create_contact_conversation') {
    $contactId = (int)($_REQUEST['contact_id'] ?? 0);
    if ($contactId <= 0) {
        errorResponse('contact_id inválido', 400);
    }
    if (!defined('INBOX_ID') || INBOX_ID <= 0) {
        errorResponse('INBOX_ID não configurado em config.php', 500);
    }

    $payload = ['contact_id' => $contactId, 'inbox_id' => INBOX_ID, 'status' => 'open', 'source_id' => 'forward-' . time()];
    $result = cwRequest('/inboxes/' . INBOX_ID . '/conversations', 'POST', $payload);
    if ($result['code'] >= 400) {
        errorResponse($result['body']['error'] ?? ($result['body']['message'] ?? 'Erro ao criar conversa'), $result['code']);
    }
    jsonResponse($result['body']);
}

if ($action === 'forward') {
    $targetConversationId = (int)($_POST['target_conversation_id'] ?? 0);
    $targetContactId = (int)($_POST['target_contact_id'] ?? 0);
    $sourceConversationId = (int)($_POST['source_conversation_id'] ?? 0);
    $selectedMessages = getRequestArray('selected_messages');

    if ($targetConversationId <= 0 && $targetContactId <= 0) {
        errorResponse('target_conversation_id ou target_contact_id é obrigatório', 400);
    }
    if ($sourceConversationId <= 0) {
        errorResponse('source_conversation_id é obrigatório', 400);
    }
    if (empty($selectedMessages)) {
        errorResponse('selected_messages obrigatórios', 400);
    }

    if ($targetConversationId <= 0) {
        $convResult = cwRequest("/contacts/{$targetContactId}/conversations");
        if ($convResult['code'] >= 400) {
            errorResponse($convResult['body']['error'] ?? ($convResult['body']['message'] ?? 'Erro ao buscar conversas do contato'), $convResult['code']);
        }
        $conversations = getPayload($convResult['body']);
        $conversations = ensureArray($conversations);
        if (!empty($conversations)) {
            $latest = getLatestConversation($conversations);
            $targetConversationId = (int)($latest['id'] ?? 0);
        }
    }

    if ($targetConversationId <= 0) {
        if (!defined('INBOX_ID') || INBOX_ID <= 0) {
            errorResponse('Nenhuma conversa encontrada e INBOX_ID não está configurado para criar nova conversa', 500);
        }
        $createResult = cwRequest('/inboxes/' . INBOX_ID . '/conversations', 'POST', ['contact_id' => $targetContactId, 'inbox_id' => INBOX_ID, 'status' => 'open', 'source_id' => 'forward-' . time()]);
        if ($createResult['code'] >= 400) {
            errorResponse($createResult['body']['error'] ?? ($createResult['body']['message'] ?? 'Erro ao criar conversa alvo'), $createResult['code']);
        }
        $created = getPayload($createResult['body']);
        $targetConversationId = (int)($created['id'] ?? 0);
    }

    if ($targetConversationId <= 0) {
        errorResponse('Não foi possível determinar o ID da conversa de destino', 500);
    }

    $body = ['content' => buildForwardText($selectedMessages, $sourceConversationId), 'message_type' => 'outgoing'];
    $attachmentFiles = prepareAttachments($selectedMessages);
    if (!empty($attachmentFiles)) {
        foreach ($attachmentFiles as $attachment) {
            $body['attachments'][] = new CURLFile($attachment['path'], $attachment['mime'], $attachment['name']);
        }
        $result = cwRequest("/conversations/{$targetConversationId}/messages", 'POST', $body, true);
        foreach ($attachmentFiles as $attachment) {
            @unlink($attachment['path']);
        }
    } else {
        $result = cwRequest("/conversations/{$targetConversationId}/messages", 'POST', $body);
    }

    if ($result['code'] >= 400) {
        errorResponse($result['body']['error'] ?? ($result['body']['message'] ?? 'Erro ao encaminhar mensagem'), $result['code']);
    }
    jsonResponse(['success' => true, 'forwarded_to_conversation_id' => $targetConversationId, 'response' => $result['body']]);
}

errorResponse('Ação inválida', 400);

function ensureArray($value): array {
    return is_array($value) ? $value : [];
}
?>