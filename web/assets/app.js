import { marked } from 'marked';
import DOMPurify from 'dompurify';
import './styles/app.css';

const app = document.querySelector('.app');
const messagesEl = document.getElementById('messages');
const modelSelect = document.getElementById('model');
const form = document.getElementById('composer');
const prompt = document.getElementById('prompt');
const sendButton = document.getElementById('send');
const resetButton = document.getElementById('reset');

// historique envoyé à chaque requête : l'API ne garde aucune mémoire
let history = [];
let controller = null;

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
    if (!busy) prompt.focus();
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

prompt.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
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
    messagesEl.innerHTML = '<div class="notice" id="empty">Nouvelle conversation. Pose ta question.</div>';
    prompt.focus();
});

prompt.focus();
