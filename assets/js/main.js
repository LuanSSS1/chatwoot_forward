let currentConversationId = null;
let currentMessages = [];
let selectedTargetContact = null;
let selectedTargetConversationId = null;

function logError(message, details = '') {
    const errorDiv = document.getElementById('error-log');
    errorDiv.style.display = 'block';
    errorDiv.innerHTML = `<strong>Erro:</strong> ${message}${details ? `<br>${details}` : ''}`;
    console.error(message, details);
}

function clearError() {
    const errorDiv = document.getElementById('error-log');
    errorDiv.style.display = 'none';
    errorDiv.innerHTML = '';
}

function setStatus(text, color = '#666') {
    const status = document.getElementById('status');
    status.textContent = text;
    status.style.color = color;
}

function setTargetInfo(html) {
    document.getElementById('target-info').innerHTML = html;
}

function safeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value;
    return div.innerHTML;
}

function fetchJson(url, options = {}) {
    return fetch(url, options)
        .then(async response => {
            const text = await response.text();
            let data;
            try {
                data = text ? JSON.parse(text) : {};
            } catch (parseErr) {
                throw new Error(`Erro de JSON: ${parseErr.message}. Resposta: ${text}`);
            }
            if (!response.ok) {
                const apiMessage = data.error || data.message || data.error_description || text;
                throw new Error(`${apiMessage || 'Erro de rede'} (HTTP ${response.status})`);
            }
            return data;
        });
}

function parseJsonSafe(value) {
    if (typeof value !== 'string') {
        return value;
    }
    try {
        return JSON.parse(value);
    } catch (err) {
        return value;
    }
}

window.addEventListener('message', function(event) {
    console.log('Chatwoot message event received', { origin: event.origin, data: event.data });
    if (!event.data) {
        return;
    }

    const raw = parseJsonSafe(event.data);
    const payload = parseJsonSafe(raw?.data ?? raw);
    const conversationPayload = parseJsonSafe(payload?.conversation ?? payload);
    const convId = conversationPayload?.id
        || payload?.conversation_id
        || payload?.conversationId
        || raw?.conversation_id
        || raw?.conversationId;

    if (convId) {
        currentConversationId = parseInt(convId, 10);
        setStatus(`✅ Conversa carregada: #${currentConversationId}`, 'green');
        const contactName = conversationPayload?.meta?.sender?.name
            || conversationPayload?.meta?.sender?.phone_number
            || conversationPayload?.meta?.sender?.email
            || payload?.contact?.name
            || payload?.contact?.phone_number
            || payload?.contact?.email
            || 'Contato desconhecido';
        document.getElementById('conv-info').innerHTML = `<strong>Conversa:</strong> #${currentConversationId} - ${safeHtml(contactName)}`;
        loadMessages();
    } else {
        console.warn('Não foi possível extrair conversation_id do evento message', { raw, payload, conversationPayload });
    }
});

function checkUrlParams() {
    const urlParams = new URLSearchParams(window.location.search);
    const convId = urlParams.get('conversation_id') || urlParams.get('conversationId');

    if (convId) {
        currentConversationId = parseInt(convId, 10);
        setStatus(`✅ ID obtido pela URL: #${currentConversationId}`, 'orange');
        document.getElementById('conv-info').innerHTML = `<strong>Conversa:</strong> #${currentConversationId}`;
        loadMessages();
    }
}

window.onload = function() {
    setStatus('Aguardando contexto do Chatwoot...');
    checkUrlParams();
    setTimeout(() => {
        if (!currentConversationId) {
            logError(
                'Não foi possível receber o contexto do Chatwoot.',
                'Verifique se o Dashboard App está configurado corretamente. O app precisa enviar conversation_id via evento message ou a URL deve incluir ?conversation_id=...'
            );
        }
    }, 2500);
};

function loadMessages() {
    clearError();
    if (!currentConversationId) {
        logError('ID da conversa não encontrado');
        return;
    }

    document.getElementById('messages-list').innerHTML = 'Carregando mensagens...';

    fetchJson(`api/chatwoot.php?action=get_messages&conversation_id=${currentConversationId}`)
        .then(data => {
            const messages = data.payload || data.messages || data;
            currentMessages = Array.isArray(messages) ? messages : [];
            renderMessages(currentMessages);
        })
        .catch(err => {
            logError('Erro ao carregar mensagens', err.message || err);
            document.getElementById('messages-list').innerHTML = '<p>Falha ao carregar mensagens.</p>';
        });
}

function getAttachmentUrl(attachment) {
    return attachment.file_url || attachment.data_url || attachment.url || attachment.content || attachment.thumb_url || '';
}

function getAttachmentName(attachment) {
    return attachment.file_name || attachment.name || attachment.filename || attachment.title || `Anexo ${attachment.id || ''}`.trim();
}

function renderMessages(messages) {
    if (!messages.length) {
        document.getElementById('messages-list').innerHTML = '<div class="message-item"><div class="message-body">Nenhuma mensagem encontrada.</div></div>';
        return;
    }

    const html = `<div class="messages-grid">${messages.map((msg, index) => {
        const author = msg.message_type === 1 ? 'Operador' : 'Cliente';
        const isOutgoing = msg.message_type === 1;
        const contentText = msg.content || msg.body || msg.content_text || '';
        const content = safeHtml(contentText || '[Mensagem sem texto]');
        const createdAt = msg.created_at ? ` em ${safeHtml(msg.created_at)}` : '';

        let attachmentHtml = '';
        if (Array.isArray(msg.attachments) && msg.attachments.length > 0) {
            attachmentHtml = '<div class="attachment-list"><strong>📎 Anexos</strong><ul>' +
                msg.attachments.map(att => {
                    const url = getAttachmentUrl(att);
                    const name = safeHtml(getAttachmentName(att) || 'Anexo');
                    return `<li>${name}${url ? ` — <a href="${safeHtml(url)}" target="_blank" rel="noopener noreferrer">abrir</a>` : ''}</li>`;
                }).join('') + '</ul><div style="font-size: 11px; color: #999; margin-top: 5px;">⚠️ Anexos não são encaminhados automaticamente.</div></div>';
        }

        return `
            <div class="message-item ${isOutgoing ? 'outgoing' : 'incoming'}">
                <div class="message-header">
                    <div class="message-author">${author}</div>
                    <div class="message-time">${createdAt}</div>
                </div>
                <div class="message-body">${content}</div>
                ${attachmentHtml}
                <label class="message-checkbox"><input type="checkbox" data-index="${index}"> Selecionar para encaminhar</label>
            </div>
        `;
    }).join('')}</div>`;

    document.getElementById('messages-list').innerHTML = html;
}

function searchContacts() {
    clearError();
    const query = document.getElementById('search-input').value.trim();
    if (!query) {
        logError('Digite um nome, telefone ou email para buscar');
        return;
    }

    document.getElementById('contacts-list').innerHTML = '<p>Buscando contatos...</p>';
    document.getElementById('conversation-list').innerHTML = '';
    selectedTargetContact = null;
    selectedTargetConversationId = null;
    setTargetInfo('');

    fetchJson(`api/chatwoot.php?action=search_contacts&q=${encodeURIComponent(query)}`)
        .then(data => {
            const contacts = data.contacts || [];
            if (!contacts.length) {
                document.getElementById('contacts-list').innerHTML = '<p>Nenhum contato encontrado.</p>';
                return;
            }

            document.getElementById('contacts-list').innerHTML = contacts.map(contact => {
                const name = safeHtml(contact.name || contact.name_text || 'Contato sem nome');
                const phone = safeHtml(contact.phone_number || contact.phone || '');
                const email = safeHtml(contact.email || '');
                const identifier = safeHtml(contact.identifier || '');
                return `
                    <div class="message-item">
                        <strong>${name}</strong><br>
                        ${phone ? `Telefone: ${phone}<br>` : ''}
                        ${email ? `Email: ${email}<br>` : ''}
                        ${identifier ? `ID: ${identifier}<br>` : ''}
                        <button type="button" onclick="selectTargetContact(${contact.id}, this, true)">Enviar Mensagens</button>
                    </div>
                `;
            }).join('');
        })
        .catch(err => {
            logError('Erro ao buscar contatos', err.message || err);
            document.getElementById('contacts-list').innerHTML = '<p>Erro ao buscar contatos.</p>';
        });
}

function selectTargetContact(contactId, button, autoSend = false) {
    selectedTargetContact = { id: contactId };
    selectedTargetConversationId = null;
    const parent = button.closest('.message-item');
    const title = parent ? parent.querySelector('strong')?.textContent || `#${contactId}` : `#${contactId}`;
    setTargetInfo(`<strong>Contato selecionado:</strong> ${safeHtml(title)}`);
    if (autoSend) {
        forwardSelected();
    } else {
        loadContactConversations(contactId);
    }
}

function loadContactConversations(contactId) {
    document.getElementById('conversation-list').innerHTML = '<p>Carregando conversas do contato...</p>';

    fetchJson(`api/chatwoot.php?action=get_contact_conversations&contact_id=${contactId}`)
        .then(data => {
            const conversations = data.conversations || [];
            if (!conversations.length) {
                document.getElementById('conversation-list').innerHTML = '<p>Contato encontrado, mas não há conversas. O sistema irá tentar criar uma conversa ao encaminhar.</p>';
                return;
            }

            document.getElementById('conversation-list').innerHTML = '<h4>📌 Conversas do contato</h4>' +
                conversations.map(conv => {
                    const convId = conv.id || conv.conversation_id || '';
                    const status = safeHtml(conv.status || conv.status_name || 'desconhecido');
                    const inbox = safeHtml(conv.inbox?.name || '');
                    return `
                        <div class="message-item">
                            <strong>Conversa #${convId}</strong><br>
                            Status: ${status}<br>
                            ${inbox ? `Inbox: ${inbox}<br>` : ''}
                            <button type="button" onclick="selectTargetConversation(${convId})">Selecionar conversa</button>
                        </div>
                    `;
                }).join('');
        })
        .catch(err => {
            logError('Erro ao carregar conversas do contato', err.message || err);
            document.getElementById('conversation-list').innerHTML = '<p>Erro ao carregar conversas.</p>';
        });
}

function selectTargetConversation(conversationId) {
    selectedTargetConversationId = conversationId;
    setTargetInfo(`<strong>Destino selecionado:</strong> conversa #${conversationId}`);
}

function forwardSelected() {
    clearError();
    if (!currentConversationId) {
        logError('Nenhuma conversa carregada na esquerda.');
        return;
    }

    const checkedBoxes = Array.from(document.querySelectorAll('#messages-list input[type="checkbox"]:checked'));
    if (!checkedBoxes.length) {
        logError('Selecione ao menos uma mensagem para encaminhar.');
        return;
    }

    if (!selectedTargetContact && !selectedTargetConversationId) {
        logError('Selecione um contato ou conversa de destino.');
        return;
    }

    const selectedIndexes = checkedBoxes.map(cb => parseInt(cb.dataset.index, 10)).filter(Number.isInteger);
    const selectedMessages = selectedIndexes.map(i => currentMessages[i]).filter(Boolean);

    if (!selectedMessages.length) {
        logError('Não foi possível determinar as mensagens selecionadas.');
        return;
    }

    // Sanitizar mensagens: remover anexos e manter apenas conteúdo essencial
    const cleanedMessages = selectedMessages.map(msg => ({
        id: msg.id,
        content: msg.content || msg.body || '',
        message_type: msg.message_type,
        created_at: msg.created_at,
        sender: msg.sender_name || msg.sender || ''
    }));

    const formData = new FormData();
    formData.append('action', 'forward');
    formData.append('source_conversation_id', currentConversationId);
    if (selectedTargetConversationId) {
        formData.append('target_conversation_id', selectedTargetConversationId);
    }
    if (selectedTargetContact) {
        formData.append('target_contact_id', selectedTargetContact.id);
    }
    formData.append('selected_messages', JSON.stringify(cleanedMessages));

    fetchJson('api/chatwoot.php', {
        method: 'POST',
        body: formData
    })
        .then(data => {
            const targetId = data.forwarded_to_conversation_id || selectedTargetConversationId;
            setStatus(`✅ Mensagem encaminhada para conversa #${targetId}`, 'green');
            if (!selectedTargetConversationId && targetId) {
                selectedTargetConversationId = targetId;
                setTargetInfo(`<strong>Destino selecionado:</strong> conversa #${targetId}`);
            }
        })
        .catch(err => {
            logError('Erro ao encaminhar mensagem', err.message || err);
        });
}

