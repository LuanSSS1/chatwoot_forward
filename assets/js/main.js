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

window.addEventListener('message', function(event) {
    console.log('Chatwoot message event received', { origin: event.origin, data: event.data });
    if (!event.data) {
        return;
    }
    const payload = event.data.data || event.data;
    const conv = payload?.conversation || payload;
    const convId = conv?.id || payload?.conversation_id || payload?.conversationId;

    if (convId) {
        currentConversationId = parseInt(convId, 10);
        setStatus(`✅ Conversa carregada: #${currentConversationId}`, 'green');
        const contactName = payload?.contact?.name || payload?.contact?.phone_number || payload?.contact?.email || 'Contato desconhecido';
        document.getElementById('conv-info').innerHTML = `<strong>Conversa:</strong> #${currentConversationId} - ${safeHtml(contactName)}`;
        loadMessages();
    } else if (payload) {
        console.warn('Evento message recebido sem conversation_id:', payload);
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

function renderMessages(messages) {
    if (!messages.length) {
        document.getElementById('messages-list').innerHTML = '<p>Nenhuma mensagem encontrada.</p>';
        return;
    }

    const html = messages.map((msg, index) => {
        const author = msg.message_type === 1 ? 'Operador' : 'Cliente';
        const content = safeHtml(msg.content || msg.body || '—');
        const createdAt = msg.created_at ? ` em ${safeHtml(msg.created_at)}` : '';

        let attachmentHtml = '';
        if (Array.isArray(msg.attachments) && msg.attachments.length > 0) {
            attachmentHtml = '<div class="attachment-list"><strong>Anexos:</strong><ul>' +
                msg.attachments.map(att => {
                    const url = safeHtml(att.file_url || att.content || att.url || '');
                    const name = safeHtml(att.file_name || att.name || url || 'anexo');
                    return `<li>${name}${url ? ` — <a href="${url}" target="_blank" rel="noopener">abrir</a>` : ''}</li>`;
                }).join('') + '</ul></div>';
        }

        return `
            <label class="message-item">
                <input type="checkbox" data-index="${index}"> <strong>[${author}]</strong>${createdAt}
                <div>${content}</div>
                ${attachmentHtml}
            </label>
        `;
    }).join('');

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
                        <button type="button" onclick="selectTargetContact(${contact.id}, this)">Selecionar contato</button>
                    </div>
                `;
            }).join('');
        })
        .catch(err => {
            logError('Erro ao buscar contatos', err.message || err);
            document.getElementById('contacts-list').innerHTML = '<p>Erro ao buscar contatos.</p>';
        });
}

function selectTargetContact(contactId, button) {
    selectedTargetContact = { id: contactId };
    selectedTargetConversationId = null;
    const parent = button.closest('.message-item');
    const title = parent ? parent.querySelector('strong')?.textContent || `#${contactId}` : `#${contactId}`;
    setTargetInfo(`<strong>Contato selecionado:</strong> ${safeHtml(title)}`);
    loadContactConversations(contactId);
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

    const formData = new FormData();
    formData.append('action', 'forward');
    formData.append('source_conversation_id', currentConversationId);
    if (selectedTargetConversationId) {
        formData.append('target_conversation_id', selectedTargetConversationId);
    }
    if (selectedTargetContact) {
        formData.append('target_contact_id', selectedTargetContact.id);
    }
    formData.append('selected_messages', JSON.stringify(selectedMessages));

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
