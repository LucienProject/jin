import { marked } from 'marked';
import DOMPurify from 'dompurify';
import './theme.js';
import './styles/app.css';

const app = document.querySelector('.app');
const messagesEl = document.getElementById('messages');
const modelSelect = document.getElementById('model');
const form = document.getElementById('composer');
const prompt = document.getElementById('prompt');
const sendButton = document.getElementById('send');
const resetButton = document.getElementById('reset');
const sidebar = document.getElementById('sidebar');
const backdrop = document.getElementById('backdrop');
const openSidebarButton = document.getElementById('open-sidebar');
const saveButton = document.getElementById('save');
const listEl = document.getElementById('conversations');
const noConversationEl = document.getElementById('no-conversation');
const conversationsUrl = sidebar.dataset.createUrl;

// historique envoyé à chaque requête : l'API ne garde aucune mémoire
let history = [];
let controller = null;
// conversation enregistrée affichée : mise à jour après chaque échange
let currentId = null;
let conversations = JSON.parse(sidebar.dataset.conversations);
// dossiers créés, même vides
let folders = JSON.parse(sidebar.dataset.folders);
let saving = false;

try {
    const saved = localStorage.getItem('jin.model');
    if (saved && [...modelSelect.options].some((o) => o.value === saved)) {
        modelSelect.value = saved;
    }
} catch {}

modelSelect.addEventListener('change', () => {
    try { localStorage.setItem('jin.model', modelSelect.value); } catch {}
});

const renderMarkdown = (text) => DOMPurify.sanitize(marked.parse(text));

function addBubble(role, label) {
    document.getElementById('empty')?.remove();
    const bubble = document.createElement('article');
    bubble.className = `msg ${role}`;
    const meta = document.createElement('div');
    meta.className = 'meta';
    meta.textContent = label;
    const body = document.createElement('div');
    body.className = 'body';
    bubble.append(meta, body);
    messagesEl.append(bubble);
    messagesEl.scrollTop = messagesEl.scrollHeight;
    return bubble;
}

function setBusy(busy) {
    sendButton.textContent = busy ? 'Stop' : 'Envoyer';
    sendButton.classList.toggle('stop', busy);
    prompt.disabled = busy;
    modelSelect.disabled = busy;
    resetButton.disabled = busy;
    listEl.querySelectorAll('button').forEach((button) => { button.disabled = busy; });
    refreshSaveButton();
    if (!busy && !touchScreen) prompt.focus();
}

async function send(question) {
    const model = modelSelect.value;
    history.push({ role: 'user', content: question });
    addBubble('user', 'Toi').querySelector('.body').textContent = question;

    const bubble = addBubble('assistant', model);
    const body = bubble.querySelector('.body');
    body.innerHTML = '<span class="typing">…</span>';
    let reasoningBody = null;
    let content = '';
    let reasoning = '';
    let frame = null;

    const render = () => {
        frame = null;
        if (reasoningBody) reasoningBody.textContent = reasoning;
        body.innerHTML = content ? renderMarkdown(content) : '<span class="typing">…</span>';
        const nearBottom = messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 120;
        if (nearBottom) messagesEl.scrollTop = messagesEl.scrollHeight;
    };
    const scheduleRender = () => { frame ??= requestAnimationFrame(render); };

    const showError = (text) => {
        const error = document.createElement('div');
        error.className = 'notice error';
        error.textContent = text;
        bubble.append(error);
    };

    controller = new AbortController();
    setBusy(true);

    try {
        const response = await fetch(app.dataset.sendUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/x-ndjson' },
            body: JSON.stringify({ model, messages: history }),
            signal: controller.signal,
        });
        // session expirée : le pare-feu redirige vers la page de connexion
        if (response.redirected || (response.ok && !response.headers.get('Content-Type')?.includes('ndjson'))) {
            window.location.reload();
            return;
        }
        if (!response.ok) {
            const problem = await response.json().catch(() => ({}));
            throw new Error(problem.detail || problem.title || `Erreur HTTP ${response.status}`);
        }

        const reader = response.body.pipeThrough(new TextDecoderStream()).getReader();
        let buffer = '';
        for (;;) {
            const { value, done } = await reader.read();
            if (done) break;
            buffer += value;
            const lines = buffer.split('\n');
            buffer = lines.pop();
            for (const line of lines.filter(Boolean)) {
                const part = JSON.parse(line);
                if (part.type === 'content') {
                    content += part.text;
                } else if (part.type === 'reasoning') {
                    if (!reasoningBody) {
                        const details = document.createElement('details');
                        details.className = 'reasoning';
                        details.innerHTML = '<summary>Réflexion</summary><pre></pre>';
                        bubble.insertBefore(details, body);
                        reasoningBody = details.querySelector('pre');
                    }
                    reasoning += part.text;
                } else if (part.type === 'error') {
                    showError(part.text);
                }
                scheduleRender();
            }
        }
    } catch (e) {
        if (e.name !== 'AbortError') showError(e.message);
    } finally {
        if (frame) cancelAnimationFrame(frame);
        render();
        if (!content) body.innerHTML = '<em class="muted">(pas de réponse)</em>';
        // on ne garde que les échanges complets dans l'historique
        if (content) {
            history.push({ role: 'assistant', content });
        } else {
            history.pop();
        }
        controller = null;
        setBusy(false);
        if (content && currentId) updateSaved();
    }
}

form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (controller) {
        controller.abort();
        return;
    }
    const question = prompt.value.trim();
    if (!question || !modelSelect.value) return;
    prompt.value = '';
    autosize();
    send(question);
});

// sur écran tactile, Entrée va à la ligne : on envoie avec le bouton
const touchScreen = window.matchMedia('(pointer: coarse)').matches;

prompt.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && !touchScreen) {
        event.preventDefault();
        form.requestSubmit();
    }
});

const autosize = () => {
    prompt.style.height = 'auto';
    prompt.style.height = `${Math.min(prompt.scrollHeight, 200)}px`;
};
prompt.addEventListener('input', autosize);

resetButton.addEventListener('click', () => {
    history = [];
    currentId = null;
    renderList();
    messagesEl.innerHTML = '<div class="notice" id="empty">Nouvelle conversation. Pose ta question.</div>';
    prompt.focus();
});

// Conversations enregistrées

/** Requête JSON ; renvoie null si la ressource n'existe plus. */
async function api(url, options = {}) {
    const response = await fetch(url, {
        ...options,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    });
    // session expirée : le pare-feu redirige vers la page de connexion
    if (response.redirected) {
        window.location.reload();
        throw new Error('Session expirée');
    }
    if (response.status === 404) return null;
    if (!response.ok) {
        const problem = await response.json().catch(() => ({}));
        throw new Error(problem.detail || problem.title || `Erreur HTTP ${response.status}`);
    }
    return response.status === 204 ? true : response.json();
}

const payload = () => {
    const first = history.find((m) => m.role === 'user')?.content.replace(/\s+/g, ' ').trim() ?? 'Conversation';
    return JSON.stringify({
        title: first.length > 60 ? `${first.slice(0, 60)}…` : first,
        model: modelSelect.value,
        messages: history,
    });
};

const formatDate = (iso) => new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });

function refreshSaveButton() {
    const saved = currentId !== null;
    saveButton.disabled = saved || saving || controller !== null || history.length === 0;
    saveButton.classList.toggle('saved', saved);
    saveButton.querySelector('.save-label').textContent = saved ? 'Enregistrée' : 'Enregistrer la conversation';
}

const FOLDER_ICON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>';

// dossiers repliés, mémorisés dans ce navigateur
let collapsed = new Set();
try { collapsed = new Set(JSON.parse(localStorage.getItem('jin.collapsedFolders') ?? '[]')); } catch {}
const saveCollapsed = () => {
    try { localStorage.setItem('jin.collapsedFolders', JSON.stringify([...collapsed])); } catch {}
};

const folderNames = () => [...new Set([...folders, ...conversations.map((c) => c.folder).filter(Boolean)])]
    .sort((a, b) => a.localeCompare(b, 'fr', { sensitivity: 'base', numeric: true }));

function iconButton(className, label, svg, onClick) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `ghost icon ${className}`;
    button.title = label;
    button.setAttribute('aria-label', label);
    button.innerHTML = svg;
    button.addEventListener('click', onClick);
    return button;
}

function conversationItem(conversation) {
    const item = document.createElement('li');
    item.className = 'item';
    item.classList.toggle('active', conversation.id === currentId);
    // sur ordinateur, se glisse sur un dossier (ou hors des dossiers)
    item.draggable = true;
    item.addEventListener('dragstart', (event) => {
        if (controller) {
            event.preventDefault();
            return;
        }
        dragged = conversation;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', conversation.title);
        item.classList.add('dragging');
    });
    item.addEventListener('dragend', () => {
        dragged = null;
        item.classList.remove('dragging');
        clearDropTarget();
    });

    const open = document.createElement('button');
    open.type = 'button';
    open.className = 'conversation';
    open.title = conversation.title;
    const title = document.createElement('span');
    title.className = 'title';
    title.textContent = conversation.title;
    const meta = document.createElement('span');
    meta.className = 'date';
    meta.textContent = `${formatDate(conversation.updatedAt)} · ${conversation.model}`;
    open.append(title, meta);
    open.addEventListener('click', () => load(conversation.id));

    const rename = iconButton('rename', `Renommer « ${conversation.title} »`,
        '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
        () => renameConversation(conversation));
    rename.title = 'Renommer';
    const move = iconButton('move', `Classer « ${conversation.title} »`, FOLDER_ICON, () => openMoveDialog(conversation));
    move.title = 'Classer dans un dossier';
    const remove = iconButton('delete', `Supprimer « ${conversation.title} »`,
        '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>',
        () => removeConversation(conversation));
    remove.title = 'Supprimer';

    item.append(open, rename, move, remove);
    item.querySelectorAll('button').forEach((button) => { button.disabled = controller !== null; });
    return item;
}

function folderGroup(folder) {
    const items = conversations.filter((c) => c.folder === folder);
    const group = document.createElement('li');
    group.className = 'folder';
    group.dataset.folder = folder;

    const details = document.createElement('details');
    // le dossier de la conversation affichée reste ouvert
    details.open = !collapsed.has(folder) || items.some((c) => c.id === currentId);
    const summary = document.createElement('summary');
    summary.innerHTML = FOLDER_ICON;
    const name = document.createElement('span');
    name.className = 'folder-name';
    name.textContent = folder;
    const count = document.createElement('span');
    count.className = 'count';
    count.textContent = items.length;
    const action = (className, label, svg, handler) => iconButton(className, label, svg, (event) => {
        event.preventDefault(); // ne replie pas le dossier
        handler();
    });
    const rename = action('rename', `Renommer le dossier « ${folder} »`,
        '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
        () => renameFolder(folder));
    rename.title = 'Renommer le dossier';
    const remove = action('delete', `Supprimer le dossier « ${folder} »`,
        '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>',
        () => deleteFolder(folder, items.length));
    remove.title = 'Supprimer le dossier (les conversations sont gardées)';
    if (controller) rename.disabled = remove.disabled = true;

    summary.append(name, count, rename, remove);
    summary.addEventListener('click', (event) => {
        if (event.defaultPrevented) return;
        if (details.open) collapsed.add(folder); else collapsed.delete(folder);
        saveCollapsed();
    });

    const list = document.createElement('ul');
    list.append(...items.map(conversationItem));
    if (items.length === 0) {
        const hint = document.createElement('li');
        hint.className = 'folder-empty muted';
        hint.textContent = touchScreen ? 'Dossier vide' : 'Dossier vide : glisse une conversation ici';
        list.append(hint);
    }
    details.append(summary, list);
    group.append(details);
    return group;
}

function renderList() {
    listEl.replaceChildren(
        ...folderNames().map(folderGroup),
        ...conversations.filter((c) => !c.folder).map(conversationItem),
    );
    noConversationEl.hidden = conversations.length > 0 || folderNames().length > 0;
    refreshSaveButton();
}

// Fenêtre « Classer dans un dossier »

const moveDialog = document.getElementById('move-dialog');
const moveForm = document.getElementById('move-form');
const moveChoices = document.getElementById('move-folders');
const newFolderInput = document.getElementById('new-folder');
let moving = null;

function openMoveDialog(conversation) {
    moving = conversation;
    document.getElementById('move-title').textContent = conversation.title;
    const choice = (label, folder) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ghost choice';
        button.classList.toggle('current', folder === (conversation.folder ?? null));
        button.disabled = folder === (conversation.folder ?? null);
        button.innerHTML = folder ? FOLDER_ICON : '';
        button.append(label);
        button.addEventListener('click', () => moveTo(folder));
        return button;
    };
    moveChoices.replaceChildren(
        ...folderNames().map((folder) => choice(folder, folder)),
        ...(conversation.folder ? [choice('Retirer du dossier', null)] : []),
    );
    moveChoices.hidden = moveChoices.children.length === 0;
    newFolderInput.value = '';
    moveDialog.showModal();
    if (!touchScreen) newFolderInput.focus();
}

function moveTo(folder) {
    const conversation = moving;
    moveDialog.close();
    if (conversation) moveConversation(conversation, folder);
}

async function moveConversation(conversation, folder) {
    try {
        const summary = await api(`${conversationsUrl}/${conversation.id}`, { method: 'PATCH', body: JSON.stringify({ folder }) });
        if (summary) {
            if (folder) {
                collapsed.delete(folder);
                if (!folders.includes(folder)) folders.push(folder);
            }
            saveCollapsed();
            conversations = conversations.map((c) => (c.id === summary.id ? summary : c));
        } else {
            conversations = conversations.filter((c) => c.id !== conversation.id);
            if (currentId === conversation.id) currentId = null;
            showSidebarError('Cette conversation n\'existe plus.');
        }
        renderList();
    } catch (e) {
        showSidebarError(`Classement impossible : ${e.message}`);
    }
}

async function renameConversation(conversation) {
    const title = window.prompt('Titre de la conversation :', conversation.title)?.trim().slice(0, 200);
    if (!title || title === conversation.title) return;
    try {
        const summary = await api(`${conversationsUrl}/${conversation.id}/title`, { method: 'PATCH', body: JSON.stringify({ title }) });
        if (summary) {
            conversations = conversations.map((c) => (c.id === summary.id ? summary : c));
        } else {
            conversations = conversations.filter((c) => c.id !== conversation.id);
            if (currentId === conversation.id) currentId = null;
            showSidebarError('Cette conversation n\'existe plus.');
        }
        renderList();
    } catch (e) {
        showSidebarError(`Renommage impossible : ${e.message}`);
    }
}

// Dossiers : renommer, supprimer

async function updateFolder(from, to) {
    try {
        const result = await api(`${conversationsUrl}/folders`, { method: 'PATCH', body: JSON.stringify({ from, to }) });
        if (collapsed.delete(from) && to) collapsed.add(to);
        saveCollapsed();
        // null : le dossier n'existait plus (modifié depuis un autre appareil), on recharge la liste
        if (!result) {
            window.location.reload();
            return;
        }
        ({ conversations, folders } = result);
        renderList();
    } catch (e) {
        showSidebarError(`Modification du dossier impossible : ${e.message}`);
    }
}

function renameFolder(folder) {
    const name = window.prompt(`Nouveau nom du dossier « ${folder} » :`, folder)?.trim().slice(0, 50);
    if (!name || name === folder) return;
    if (folderNames().includes(name) && !window.confirm(`Le dossier « ${name} » existe déjà. Fusionner les deux ?`)) return;
    updateFolder(folder, name);
}

function deleteFolder(folder, count) {
    const kept = { 0: '', 1: ' (sa conversation est gardée, hors dossier)' }[count] ?? ` (ses ${count} conversations sont gardées, hors dossier)`;
    if (!window.confirm(`Supprimer le dossier « ${folder} » ?${kept}`)) return;
    updateFolder(folder, null);
}

document.getElementById('new-folder-button').addEventListener('click', async () => {
    const name = window.prompt('Nom du nouveau dossier :')?.trim().slice(0, 50);
    if (!name) return;
    try {
        folders = await api(`${conversationsUrl}/folders`, { method: 'POST', body: JSON.stringify({ name }) });
        collapsed.delete(name);
        saveCollapsed();
        renderList();
    } catch (e) {
        showSidebarError(`Création du dossier impossible : ${e.message}`);
    }
});

// Glisser-déposer : sur un dossier pour y ranger, ailleurs dans la liste pour sortir du dossier

let dragged = null;

const dropFolder = (event) => {
    const group = event.target.closest('.folder');
    return group ? group.dataset.folder : null;
};

function clearDropTarget() {
    listEl.classList.remove('drop-target');
    listEl.querySelectorAll('.folder.drop-target').forEach((el) => el.classList.remove('drop-target'));
}

listEl.addEventListener('dragover', (event) => {
    if (!dragged) return;
    const folder = dropFolder(event);
    clearDropTarget();
    if (folder === (dragged.folder ?? null)) return; // déjà rangée là
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    (folder ? event.target.closest('.folder') : listEl).classList.add('drop-target');
});
listEl.addEventListener('dragleave', (event) => {
    if (!listEl.contains(event.relatedTarget)) clearDropTarget();
});
listEl.addEventListener('drop', (event) => {
    if (!dragged) return;
    event.preventDefault();
    const conversation = dragged;
    const folder = dropFolder(event);
    clearDropTarget();
    if (folder !== (conversation.folder ?? null)) moveConversation(conversation, folder);
});

// Entrée dans le champ crée le dossier
moveForm.addEventListener('submit', (event) => {
    event.preventDefault();
    const folder = newFolderInput.value.trim();
    if (folder) moveTo(folder);
});
document.getElementById('move-cancel').addEventListener('click', () => moveDialog.close());
moveDialog.addEventListener('close', () => { moving = null; });
// clic sur le fond : ferme la fenêtre
moveDialog.addEventListener('click', (event) => {
    if (event.target === moveDialog) moveDialog.close();
});

/** Remplace (ou ajoute) le résumé d'une conversation, en tête de liste. */
function upsert(summary) {
    conversations = [summary, ...conversations.filter((c) => c.id !== summary.id)];
    renderList();
}

function showSidebarError(text) {
    sidebar.querySelector('.sidebar-error')?.remove();
    const error = document.createElement('div');
    error.className = 'notice error sidebar-error';
    error.textContent = text;
    saveButton.after(error);
    setTimeout(() => error.remove(), 5000);
}

saveButton.addEventListener('click', async () => {
    saving = true;
    refreshSaveButton();
    try {
        const summary = await api(conversationsUrl, { method: 'POST', body: payload() });
        currentId = summary.id;
        upsert(summary);
    } catch (e) {
        showSidebarError(`Enregistrement impossible : ${e.message}`);
    } finally {
        saving = false;
        refreshSaveButton();
    }
});

// les mises à jour s'enchaînent pour ne jamais écraser un état plus récent
let pendingUpdate = Promise.resolve();
function updateSaved() {
    const id = currentId;
    const body = payload();
    pendingUpdate = pendingUpdate.then(async () => {
        try {
            const summary = await api(`${conversationsUrl}/${id}`, { method: 'PUT', body });
            if (summary) {
                upsert(summary);
                return;
            }
            // supprimée depuis un autre appareil : la conversation affichée redevient non enregistrée
            conversations = conversations.filter((c) => c.id !== id);
            if (currentId === id) currentId = null;
            renderList();
        } catch (e) {
            showSidebarError(`Mise à jour impossible : ${e.message}`);
        }
    });
}

async function load(id) {
    if (controller) return;
    try {
        const conversation = await api(`${conversationsUrl}/${id}`);
        if (!conversation) {
            conversations = conversations.filter((c) => c.id !== id);
            renderList();
            showSidebarError('Cette conversation n\'existe plus.');
            return;
        }
        history = conversation.messages;
        currentId = conversation.id;
        if ([...modelSelect.options].some((o) => o.value === conversation.model)) {
            modelSelect.value = conversation.model;
        }
        messagesEl.replaceChildren();
        for (const message of history) {
            const body = addBubble(message.role, message.role === 'user' ? 'Toi' : conversation.model).querySelector('.body');
            if (message.role === 'user') {
                body.textContent = message.content;
            } else {
                body.innerHTML = renderMarkdown(message.content);
            }
        }
        messagesEl.scrollTop = messagesEl.scrollHeight;
        renderList();
        closeSidebar();
        if (!touchScreen) prompt.focus();
    } catch (e) {
        showSidebarError(`Chargement impossible : ${e.message}`);
    }
}

async function removeConversation(conversation) {
    if (!window.confirm(`Supprimer « ${conversation.title} » ?`)) return;
    try {
        await api(`${conversationsUrl}/${conversation.id}`, { method: 'DELETE' });
        conversations = conversations.filter((c) => c.id !== conversation.id);
        // la conversation reste affichée, mais n'est plus enregistrée
        if (currentId === conversation.id) currentId = null;
        renderList();
    } catch (e) {
        showSidebarError(`Suppression impossible : ${e.message}`);
    }
}

// Sur mobile, la liste s'ouvre par-dessus la conversation

function openSidebar() {
    sidebar.classList.add('open');
    backdrop.hidden = false;
    openSidebarButton.setAttribute('aria-expanded', 'true');
}

function closeSidebar() {
    sidebar.classList.remove('open');
    backdrop.hidden = true;
    openSidebarButton.setAttribute('aria-expanded', 'false');
}

openSidebarButton.addEventListener('click', openSidebar);
document.getElementById('close-sidebar').addEventListener('click', closeSidebar);
backdrop.addEventListener('click', closeSidebar);
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar();
});

renderList();
if (!touchScreen) prompt.focus();
