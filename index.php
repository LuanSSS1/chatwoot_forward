<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Encaminhar • Chatwoot</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    
   
</head>
<body class="bg-gray-50 min-h-screen">

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="bg-white border-b sticky top-0 z-50 shadow-sm">
        <div class="px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <i class="fa-solid fa-share-from-square text-blue-600 text-2xl"></i>
                <h1 class="text-2xl font-semibold text-gray-800">Encaminhar Mensagens</h1>
            </div>
            <div id="status" class="text-sm font-medium px-4 py-2 rounded-full bg-gray-100 text-gray-600"></div>
        </div>
    </div>

    <div class="flex split-layout h-[calc(100vh-73px)]">
        
        <!-- COLUNA ESQUERDA - Mensagens da Origem -->
        <div class="w-full md:w-5/12 lg:w-2/5 border-r bg-white flex flex-col">
            <div class="p-4 border-b bg-gray-50">
                <h2 class="font-semibold text-gray-700 flex items-center gap-2">
                    <i class="fa-solid fa-comments"></i>
                    Mensagens da Conversa <span id="source-conv-id" class="text-blue-600"></span>
                </h2>
            </div>
            
            <div id="messages-list" class="flex-1 overflow-auto p-4 space-y-4 bg-gray-50">
                <!-- Carregado via JS -->
            </div>
        </div>

        <!-- COLUNA DIREITA - Busca e Destino -->
        <div class="w-full md:w-7/12 lg:w-3/5 flex flex-col bg-white">
            
            <!-- Barra de Pesquisa -->
            <div class="p-4 border-b bg-white sticky top-0 z-40">
                <div class="relative">
                    <input type="text" id="search-input" 
                           class="w-full pl-12 pr-4 py-3 border border-gray-300 rounded-2xl focus:outline-none focus:border-blue-500 text-base"
                           placeholder="Buscar contato por nome, telefone ou email...">
                    <i class="fa-solid fa-magnifying-glass absolute left-4 top-3.5 text-gray-400"></i>
                    <button onclick="searchContacts()" 
                            class="absolute right-2 top-1.5 px-6 py-2 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition">
                        Buscar
                    </button>
                </div>
            </div>

            <!-- Área de Destino -->
            <div class="p-4 border-b bg-gray-50">
                <h3 class="font-semibold text-gray-700 mb-2">Destino</h3>
                <div id="target-info" class="min-h-[52px] flex items-center text-sm"></div>
            </div>

            <!-- Resultados -->
            <div class="flex-1 overflow-auto p-4" id="results-area">
                <div id="contacts-list" class="space-y-3"></div>
                <div id="conversation-list" class="space-y-3"></div>
            </div>

            <!-- Botão de Encaminhar -->
            <div class="p-4 border-t bg-white sticky bottom-0">
                <button onclick="forwardSelected()" 
                        class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold py-4 rounded-2xl text-lg shadow-lg shadow-blue-500/30 transition-all active:scale-95 flex items-center justify-center gap-3">
                    <i class="fa-solid fa-paper-plane"></i>
                    ENCAMINHAR MENSAGENS SELECIONADAS
                </button>
            </div>
        </div>
    </div>
</div>

<div id="error-log" class="fixed bottom-4 right-4 max-w-xs bg-red-50 border border-red-200 text-red-700 p-4 rounded-2xl shadow-xl hidden"></div>

<script src="assets/js/main.js?v=<?= time() ?>"></script>