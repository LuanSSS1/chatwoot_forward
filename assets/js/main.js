// ==================== CONFIGURAÇÕES GLOBAIS ====================
let currentConversationId = null;
let currentMessages = [];
let selectedTargetContact = null;
let selectedTargetConversationId = null;

// ==================== FUNÇÕES AUXILIARES ====================

function setStatus(text, type = 'info') {
    const statusEl = document.getElementById('status');
    let classes = 'text-sm font-medium px-4 py-2 rounded-full ';

    if (type === 'success') classes += 'bg-green-100 text-green-700';
    else if (type === 'error') classes += 'bg-red-100 text-red-700';
    else classes += 'bg-gray-100 text-gray-600';

    statusEl.className = classes;
    statusEl.textContent = text;
}

function logError(message) {
    const errorDiv = document.getElementById('error-log');
    errorDiv.innerHTML = `
        <div class="flex items-start gap-3">
            <i class="fa-solid fa-circle-exclamation text-red-500 mt-0.5"></i>
            <div>
                <strong class="block text-red-800">Erro</strong>
                <span class="text-red-700">${message}</span>
            </div>
            <button onclick="this.closest('#error-log').classList.add('hidden')" 
                    class="ml-auto text-red-400 hover:text-red-600 text-xl leading-none">×</button>
        </div>
    `;
    errorDiv.classList.remove('hidden');
    
    // Auto esconder após 8 segundos
    setTimeout(() => {
        if (!errorDiv.classList.contains('hidden')) {
            errorDiv.classList.add('hidden');
        }
    }, 8000);
}

function clearError() {
    document.getElementById('error-log').classList.add('hidden');
}

function safeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value || '';
    return div.innerHTML;
}

function fetchJson(url, options = {}) {
    return fetch(url, options)
        .then(async response => {
            const text = await response.text();
            let data;
            try {
                data = text ? JSON.parse(text) : {};
            } catch (e) {
                throw new Error('Resposta inválida do servidor');
            }

            if (!response.ok) {
                const errorMsg = data.error || data.message || 'Erro ao processar requisição';
                throw new Error(errorMsg);
            }
            return data;
        });
}

// ==================== RENDERIZAÇÃO DE MENSAGENS ====================

function renderMessages(messages) {
    const container = document.getElementById('messages-list');
    
    if (!messages || messages.length === 0) {
        container.innerHTML = `
            <div class="text-center py-12 text-gray-500">
                <i class="fa-solid fa-inbox text-4xl mb-3 opacity-50"></i>
                <p>Nenhuma mensagem encontrada</p>
            </div>`;
        return;
    }

    const reversedMessages = [...messages].reverse();

    let html = '';
    reversedMessages.forEach((msg, index) => {
        const isOutgoing = msg.message_type === 1;
        const content = msg.content || msg.body || '[Mensagem sem texto]';
        
        const time = msg.created_at 
            ? new Date(msg.created_at).toLocaleString('pt-BR', { 
                hour: '2-digit', 
                minute: '2-digit' 
              }) 
            : '';

        let attachmentInfo = '';
        if (Array.isArray(msg.attachments) && msg.attachments.length > 0) {
            attachmentInfo = `
                <div class="mt-2 flex items-center gap-1.5 text-amber-600 text-xs bg-amber-50 px-2.5 py-1 rounded-lg w-fit">
                    <i class="fa-solid fa-paperclip"></i>
                    <span>${msg.attachments.length} anexo</span>
                </div>`;
        }

        html += `
        <div class="bg-white border border-gray-100 rounded-2xl px-4 py-3 message-item">
            <div class="flex justify-between items-center mb-1.5">
                <span class="font-medium text-xs ${isOutgoing ? 'text-blue-600' : 'text-gray-600'}">
                    ${isOutgoing ? '👤 Operador' : '🧑 Cliente'}
                </span>
                <span class="text-[10px] text-gray-400">${time}</span>
            </div>
            
            <div class="${isOutgoing ? 'outgoing' : 'incoming'}">
                <div class="chat-bubble text-[14.5px] leading-relaxed py-2 px-3">
                    ${safeHtml(content)}
                </div>
            </div>
            
            ${attachmentInfo}
            
            <label class="mt-3 flex items-center gap-2 cursor-pointer text-xs">
                <input type="checkbox" data-index="${messages.length - 1 - index}" 
                       class="w-4 h-4 accent-blue-600">
                <span class="text-gray-500">Selecionar</span>
            </label>
        </div>`;
    });

    container.innerHTML = html;
}

// ==================== BUSCA E DESTINO ====================

function searchContacts() {
    clearError();
    const query = document.getElementById('search-input').value.trim();
    
    if (!query) {
        logError('Digite algo para buscar (nome, telefone ou email)');
        return;
    }

    const contactsList = document.getElementById('contacts-list');
    const conversationList = document.getElementById('conversation-list');
    
    contactsList.innerHTML = '<p class="text-gray-500 py-8 text-center">Buscando...</p>';
    conversationList.innerHTML = '';
    
    selectedTargetContact = null;
    selectedTargetConversationId = null;
    document.getElementById('target-info').innerHTML = '';

    fetchJson(`api/chatwoot.php?action=search_contacts&q=${encodeURIComponent(query)}`)
        .then(data => {
            const contacts = data.contacts || [];
            
            if (!contacts.length) {
                contactsList.innerHTML = `
                    <div class="text-center py-10 text-gray-500">
                        Nenhum contato encontrado
                    </div>`;
                return;
            }

            let html = '<h4 class="font-medium text-gray-600 mb-3 px-1">Resultados encontrados:</h4>';
            
            contacts.forEach(contact => {
                const name = safeHtml(contact.name || 'Sem nome');
                const phone = safeHtml(contact.phone_number || contact.phone || '');
                const email = safeHtml(contact.email || '');
                
                html += `
                <div class="bg-white border border-gray-200 rounded-2xl p-4 hover:border-blue-300 transition cursor-pointer"
                     onclick="selectTargetContact(${contact.id}, this)">
                    <div class="font-semibold text-gray-800">${name}</div>
                    ${phone ? `<div class="text-sm text-gray-600 mt-1">${phone}</div>` : ''}
                    ${email ? `<div class="text-sm text-gray-600">${email}</div>` : ''}
                </div>`;
            });
            
            contactsList.innerHTML = html;
        })
        .catch(err => {
            logError('Erro ao buscar contatos: ' + err.message);
            contactsList.innerHTML = '<p class="text-red-500">Erro ao buscar contatos.</p>';
        });
}

function selectTargetContact(contactId, element) {
    selectedTargetContact = { id: contactId };
    selectedTargetConversationId = null;

    // Destaca o item selecionado
    document.querySelectorAll('#contacts-list > div').forEach(div => {
        div.classList.remove('ring-2', 'ring-blue-500');
    });
    element.classList.add('ring-2', 'ring-blue-500');

    document.getElementById('target-info').innerHTML = `
        <div class="flex items-center gap-3 bg-blue-50 border border-blue-200 rounded-2xl p-4">
            <i class="fa-solid fa-user-check text-blue-600"></i>
            <div>
                <strong>Contato selecionado</strong><br>
                <span class="text-sm text-gray-600">ID: ${contactId}</span>
            </div>
        </div>`;
    
    loadContactConversations(contactId);
}

function loadContactConversations(contactId) {
    const convList = document.getElementById('conversation-list');
    convList.innerHTML = '<p class="text-gray-500 py-6 text-center">Carregando conversas do contato...</p>';

    fetchJson(`api/chatwoot.php?action=get_contact_conversations&contact_id=${contactId}`)
        .then(data => {
            const conversations = data.conversations || [];
            
            if (!conversations.length) {
                convList.innerHTML = `
                    <div class="bg-yellow-50 border border-yellow-200 rounded-2xl p-5 text-center">
                        <p class="text-yellow-700">Nenhuma conversa encontrada.</p>
                        <p class="text-xs text-yellow-600 mt-1">Uma nova conversa será criada automaticamente ao encaminhar.</p>
                    </div>`;
                return;
            }

            let html = '<h4 class="font-medium text-gray-600 mb-3 px-1">Escolha uma conversa existente:</h4>';
            
            conversations.forEach(conv => {
                const id = conv.id || conv.conversation_id;
                html += `
                <div onclick="selectTargetConversation(${id})" 
                     class="bg-white border border-gray-200 hover:border-indigo-300 rounded-2xl p-4 cursor-pointer transition">
                    <strong class="text-gray-800">Conversa #${id}</strong><br>
                    <span class="text-sm text-gray-500">Status: ${conv.status || 'open'}</span>
                </div>`;
            });
            
            convList.innerHTML = html;
        })
        .catch(err => {
            logError('Erro ao carregar conversas: ' + err.message);
        });
}

function selectTargetConversation(conversationId) {
    selectedTargetConversationId = conversationId;
    
    document.getElementById('target-info').innerHTML = `
        <div class="flex items-center gap-3 bg-emerald-50 border border-emerald-200 rounded-2xl p-4">
            <i class="fa-solid fa-check-circle text-emerald-600"></i>
            <div>
                <strong>Conversa selecionada</strong><br>
                <span class="text-sm text-gray-600">#${conversationId}</span>
            </div>
        </div>`;
}

// ==================== ENCAMINHAR MENSAGENS ====================

function forwardSelected() {
    clearError();

    if (!currentConversationId) {
        logError('Nenhuma conversa de origem carregada.');
        return;
    }

    const checked = Array.from(document.querySelectorAll('#messages-list input[type="checkbox"]:checked'));
    
    if (!checked.length) {
        logError('Selecione pelo menos uma mensagem para encaminhar.');
        return;
    }

    if (!selectedTargetContact && !selectedTargetConversationId) {
        logError('Selecione um contato ou conversa de destino.');
        return;
    }

    const selectedIndexes = checked.map(cb => parseInt(cb.dataset.index));
    const selectedMessages = selectedIndexes.map(i => currentMessages[i]).filter(Boolean);

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

    setStatus('Enviando mensagens...', 'info');

    fetchJson('api/chatwoot.php', {
        method: 'POST',
        body: formData
    })
    .then(data => {
        const targetId = data.forwarded_to_conversation_id || selectedTargetConversationId;
        setStatus(`✅ Enviado com sucesso para #${targetId}`, 'success');
        
        // Atualiza destino se foi criada nova conversa
        if (targetId && !selectedTargetConversationId) {
            selectedTargetConversationId = targetId;
        }
    })
    .catch(err => {
        logError(err.message || 'Falha ao encaminhar mensagens');
        setStatus('Erro ao encaminhar', 'error');
    });
}

// ==================== INICIALIZAÇÃO ====================

window.addEventListener('message', function(event) {
    if (!event.data) return;

    try {
        const raw = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
        const payload = raw.data || raw;
        const conversation = payload.conversation || payload;

        const convId = conversation.id || 
                      payload.conversation_id || 
                      payload.conversationId;

        if (convId) {
            currentConversationId = parseInt(convId, 10);
            setStatus(`Conversa #${currentConversationId} carregada`, 'success');
            
            document.getElementById('source-conv-id').textContent = `#${currentConversationId}`;
            loadMessages();
        }
    } catch (e) {
        console.warn('Erro ao processar evento do Chatwoot', e);
    }
});

function checkUrlParams() {
    const params = new URLSearchParams(window.location.search);
    const convId = params.get('conversation_id') || params.get('conversationId');
    
    if (convId) {
        currentConversationId = parseInt(convId, 10);
        setStatus(`Carregando conversa #${currentConversationId}`, 'info');
        document.getElementById('source-conv-id').textContent = `#${currentConversationId}`;
        loadMessages();
    }
}

function loadMessages() {
    if (!currentConversationId) return;

    document.getElementById('messages-list').innerHTML = `
        <div class="text-center py-12">
            <i class="fa-solid fa-spinner fa-spin text-3xl text-gray-400"></i>
            <p class="mt-4 text-gray-500">Carregando mensagens...</p>
        </div>`;

    fetchJson(`api/chatwoot.php?action=get_messages&conversation_id=${currentConversationId}`)
        .then(data => {
            currentMessages = data.payload || data.messages || data || [];
            renderMessages(currentMessages);
        })
        .catch(err => {
            logError('Erro ao carregar mensagens: ' + err.message);
            document.getElementById('messages-list').innerHTML = `
                <p class="text-red-500 text-center py-8">Falha ao carregar mensagens.</p>`;
        });
}

window.onload = function() {
    setStatus('Aguardando contexto do Chatwoot...');
    checkUrlParams();

    // Timeout de segurança
    setTimeout(() => {
        if (!currentConversationId) {
            setStatus('Nenhuma conversa detectada', 'error');
        }
    }, 3000);
};