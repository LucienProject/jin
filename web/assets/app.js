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
const webSearchButton = document.getElementById('web-search');
const sidebar = document.getElementById('sidebar');
const backdrop = document.getElementById('backdrop');
const openSidebarButton = document.getElementById('open-sidebar');
const saveButton = document.getElementById('save');
const listEl = document.getElementById('conversations');
const noConversationEl = document.getElementById('no-conversation');
const conversationsUrl = sidebar.dataset.createUrl;
const attachButton = document.getElementById('attach');
const fileInput = document.getElementById('file-input');
const pendingList = document.getElementById('pending-attachments');
const imageModeButton = document.getElementById('image-mode');
const imageFormatSelect = document.getElementById('image-format');
const micButton = document.getElementById('mic');
const micStatus = document.getElementById('mic-status');
const micLanguageButton = document.getElementById('mic-language');

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
    renderPending();
});

const renderMarkdown = (text) => DOMPurify.sanitize(marked.parse(text));

// Recherche web : le modèle peut chercher et lire des pages (choix mémorisé dans ce navigateur)
let webSearch = false;
try { webSearch = localStorage.getItem('jin.webSearch') === '1'; } catch {}
const refreshWebSearch = () => {
    webSearchButton.setAttribute('aria-pressed', String(webSearch));
    webSearchButton.title = webSearch
        ? 'Recherche web activée : le modèle peut chercher sur internet (cliquer pour désactiver)'
        : 'Recherche web désactivée (cliquer pour laisser le modèle chercher sur internet)';
};
webSearchButton.addEventListener('click', () => {
    webSearch = !webSearch;
    try { localStorage.setItem('jin.webSearch', webSearch ? '1' : '0'); } catch {}
    refreshWebSearch();
});
refreshWebSearch();

// Fichiers joints : le serveur en extrait le texte dès qu'ils sont choisis,
// puis ce texte part avec le message (et est enregistré avec la conversation).
// Les images sont réduites dans le navigateur et envoyées telles quelles aux modèles qui savent les lire.

const MAX_FILE_BYTES = 20 * 1024 * 1024;
const MAX_IMAGE_SIDE = 1536;
const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/bmp', 'image/avif'];
const isImage = (file) => IMAGE_TYPES.includes(file.type);
const modelSeesImages = () => modelSelect.selectedOptions[0]?.hasAttribute('data-vision') ?? false;
const FILE_ICON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>';
// {name, kind: file|image, status: loading|ready|failed, data?: {name, content, truncated} | {name, url}, error?}
let pending = [];

const formatSize = (chars) => (chars < 1000 ? `${chars} car.` : `${Math.round(chars / 1000)} k car.`);

/** Étiquette d'un fichier joint ; `info` précise sa taille ou son état ; `thumbnail` : aperçu d'une image. */
function attachmentChip(name, info, className = '', thumbnail = null) {
    const chip = document.createElement('li');
    chip.className = `attachment ${className}`;
    chip.title = name;
    if (thumbnail) {
        const img = document.createElement('img');
        img.className = 'thumbnail';
        img.src = thumbnail;
        img.alt = '';
        chip.append(img);
    } else {
        chip.innerHTML = FILE_ICON;
    }
    const label = document.createElement('span');
    label.className = 'name';
    label.textContent = name;
    const detail = document.createElement('span');
    detail.className = 'info';
    detail.textContent = info;
    chip.append(label, detail);
    return chip;
}

const describe = (attachment) => (attachment.truncated ? `tronqué à ${formatSize(attachment.content.length)}` : formatSize(attachment.content.length));

function renderPending() {
    const blind = !modelSeesImages();
    pendingList.replaceChildren(...pending.map((item) => {
        let chip;
        if (item.status !== 'ready') {
            chip = attachmentChip(item.name, item.status === 'loading' ? 'lecture…' : item.error, item.status);
        } else if (item.kind === 'image') {
            chip = attachmentChip(item.name, blind ? 'non vue par ce modèle' : 'image', blind ? 'truncated' : '', item.data.url);
            if (blind) chip.title = `${item.name} : ${modelSelect.value} ne sait pas lire les images, choisis un modèle qui le peut (gemma, qwen3.6, mistral…)`;
        } else {
            chip = attachmentChip(item.name, describe(item.data), item.data.truncated ? 'truncated' : '');
            if (item.data.truncated) chip.title = `${item.name} : trop long, seul le début sera lu par le modèle`;
        }
        chip.append(iconButton('remove', `Retirer « ${item.name} »`,
            '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
            () => {
                pending = pending.filter((other) => other !== item);
                renderPending();
            }));
        return chip;
    }));
    pendingList.hidden = pending.length === 0;
    refreshSendButton();
}

// on attend la fin des lectures avant d'envoyer
function refreshSendButton() {
    sendButton.disabled = !controller && (!modelSelect.value || pending.some((item) => item.status === 'loading'));
}

/** Image en data URL PNG/JPEG, réduite à MAX_IMAGE_SIDE px de côté. */
async function imageDataUrl(file) {
    let bitmap;
    try {
        bitmap = await createImageBitmap(file);
    } catch {
        throw new Error('image illisible');
    }
    const scale = Math.min(1, MAX_IMAGE_SIDE / Math.max(bitmap.width, bitmap.height));
    // déjà petite et dans un format que lisent les modèles : on la garde intacte
    if (scale === 1 && ['image/png', 'image/jpeg'].includes(file.type) && file.size <= 2 * 1024 * 1024) {
        bitmap.close();
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(new Error('image illisible'));
            reader.readAsDataURL(file);
        });
    }
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    const context = canvas.getContext('2d');
    // fond blanc : le JPEG n'a pas de transparence
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();
    return canvas.toDataURL('image/jpeg', 0.85);
}

async function attach(file) {
    const item = { name: file.name, kind: isImage(file) ? 'image' : 'file', status: 'loading' };
    pending.push(item);
    renderPending();
    try {
        if (file.size > MAX_FILE_BYTES) throw new Error('trop volumineux (20 Mo max.)');
        if (item.kind === 'image') {
            item.data = { name: file.name, url: await imageDataUrl(file) };
            item.status = 'ready';
            return;
        }
        const data = new FormData();
        data.append('file', file);
        const response = await fetch(form.dataset.attachUrl, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
        // session expirée : le pare-feu redirige vers la page de connexion
        if (response.redirected) {
            window.location.reload();
            return;
        }
        if (!response.ok) {
            const problem = await response.json().catch(() => ({}));
            throw new Error(problem.detail || (response.status === 413 ? 'trop volumineux' : `erreur HTTP ${response.status}`));
        }
        item.data = await response.json();
        item.status = 'ready';
    } catch (e) {
        item.status = 'failed';
        item.error = e.message;
    } finally {
        // retiré pendant la lecture : rien à afficher
        if (pending.includes(item)) renderPending();
    }
}

const attachAll = (files) => [...files].forEach(attach);

attachButton.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', () => {
    attachAll(fileInput.files);
    fileInput.value = '';
});
// fichiers collés (copier depuis l'explorateur de fichiers)
prompt.addEventListener('paste', (event) => {
    if (event.clipboardData?.files.length) {
        event.preventDefault();
        attachAll(event.clipboardData.files);
    }
});
// fichiers glissés n'importe où sur la conversation
const draggingFiles = (event) => event.dataTransfer?.types.includes('Files');
app.addEventListener('dragover', (event) => {
    if (!draggingFiles(event)) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = attachButton.disabled ? 'none' : 'copy';
    form.classList.add('drop-target');
});
app.addEventListener('dragleave', (event) => {
    if (!app.contains(event.relatedTarget)) form.classList.remove('drop-target');
});
app.addEventListener('drop', (event) => {
    if (!draggingFiles(event)) return;
    event.preventDefault();
    form.classList.remove('drop-target');
    if (!attachButton.disabled) attachAll(event.dataTransfer.files);
});

/** Contenu d'un message de l'utilisateur : images, fichiers joints puis texte. */
function fillUserBubble(bubble, message) {
    const body = bubble.querySelector('.body');
    if (message.images?.length) addImages(bubble, message.images, false);
    if (message.attachments?.length) {
        const list = document.createElement('ul');
        list.className = 'attachments';
        list.append(...message.attachments.map((a) => attachmentChip(a.name, describe(a), a.truncated ? 'truncated' : '')));
        bubble.insertBefore(list, body);
    }
    body.textContent = message.content;
    body.hidden = !message.content;
}

// Mode image : la demande part vers le générateur d'images (Z-Image-Turbo) au lieu du modèle de chat
let imageMode = false;
const refreshImageMode = () => {
    imageModeButton.setAttribute('aria-pressed', String(imageMode));
    imageModeButton.title = imageMode
        ? 'Mode image : la demande génère une image (cliquer pour revenir à la discussion)'
        : 'Générer une image (Z-Image-Turbo)';
    imageFormatSelect.hidden = !imageMode;
    form.classList.toggle('image-mode-on', imageMode);
    prompt.placeholder = imageMode ? 'Décris l\'image à générer…' : 'Pose ta question…';
};
imageModeButton.addEventListener('click', () => {
    imageMode = !imageMode;
    refreshImageMode();
    if (!touchScreen) prompt.focus();
});

/** Images d'un message ; celles générées ont un lien de téléchargement. */
function addImages(bubble, images, downloadable) {
    const gallery = document.createElement('div');
    gallery.className = 'images';
    gallery.append(...images.map((image) => {
        const figure = document.createElement('figure');
        const img = document.createElement('img');
        img.src = image.url;
        img.alt = image.name;
        img.title = `${image.name} (cliquer pour agrandir)`;
        img.addEventListener('click', () => img.classList.toggle('expanded'));
        figure.append(img);
        if (downloadable) {
            const caption = document.createElement('figcaption');
            const link = document.createElement('a');
            link.href = image.url;
            link.download = image.name;
            link.textContent = 'Télécharger';
            caption.append(link);
            figure.append(caption);
        }
        return figure;
    }));
    bubble.insertBefore(gallery, bubble.querySelector('.body'));
}

async function generateImage(description) {
    const message = { role: 'user', content: description };
    history.push(message);
    fillUserBubble(addBubble('user', 'Toi'), message);

    const bubble = addBubble('assistant', 'Z-Image-Turbo');
    const body = bubble.querySelector('.body');
    const started = Date.now();
    const tick = () => {
        body.innerHTML = `<span class="typing">Génération de l'image… ${Math.round((Date.now() - started) / 1000)} s</span>`;
    };
    tick();
    const timer = setInterval(tick, 1000);
    let image = null;

    controller = new AbortController();
    setBusy(true);
    try {
        const response = await fetch(app.dataset.imageUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ prompt: description, format: imageFormatSelect.value }),
            signal: controller.signal,
        });
        if (response.redirected) {
            window.location.reload();
            return;
        }
        if (!response.ok) {
            const problem = await response.json().catch(() => ({}));
            throw new Error(problem.detail || problem.title || `Erreur HTTP ${response.status}`);
        }
        image = await response.json();
        body.remove();
        addImages(bubble, [{ name: image.name, url: image.url }], true);
        const meta = document.createElement('div');
        meta.className = 'meta';
        meta.textContent = `en ${Math.round(image.seconds)} s`;
        bubble.append(meta);
    } catch (e) {
        body.innerHTML = '<em class="muted">(pas d\'image)</em>';
        if (e.name !== 'AbortError') {
            const error = document.createElement('div');
            error.className = 'notice error';
            error.textContent = e.message;
            bubble.append(error);
        }
    } finally {
        clearInterval(timer);
        if (image) {
            history.push({ role: 'assistant', content: '', images: [{ name: image.name, url: image.url }] });
        } else {
            history.pop();
        }
        controller = null;
        setBusy(false);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        if (image && currentId) updateSaved();
    }
}

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
    imageModeButton.disabled = busy;
    imageFormatSelect.disabled = busy;
    modelSelect.disabled = busy;
    resetButton.disabled = busy;
    listEl.querySelectorAll('button').forEach((button) => { button.disabled = busy; });
    refreshSaveButton();
    refreshSendButton();
    if (!busy && !touchScreen) prompt.focus();
}

async function send(question, attachments, images) {
    const model = modelSelect.value;
    const message = {
        role: 'user',
        content: question,
        ...(attachments.length ? { attachments } : {}),
        ...(images.length ? { images } : {}),
    };
    history.push(message);
    fillUserBubble(addBubble('user', 'Toi'), message);

    const bubble = addBubble('assistant', model);
    const body = bubble.querySelector('.body');
    body.innerHTML = '<span class="typing">…</span>';
    let reasoningBody = null;
    let content = '';
    let reasoning = '';
    let frame = null;
    let toolsBlock = null;
    let lastStep = null;
    let steps = 0;

    // étapes de recherche, dans un bloc repliable ouvert pendant la recherche
    const addStep = (part) => {
        if (!toolsBlock) {
            toolsBlock = document.createElement('details');
            toolsBlock.className = 'tools';
            toolsBlock.open = true;
            toolsBlock.innerHTML = '<summary></summary><ol></ol>';
            bubble.insertBefore(toolsBlock, body);
        }
        steps++;
        toolsBlock.querySelector('summary').textContent = `Recherche web (${steps} étape${steps > 1 ? 's' : ''})`;
        lastStep = document.createElement('li');
        lastStep.className = `step ${part.name} running`;
        const label = document.createElement(part.url ? 'a' : 'span');
        label.textContent = part.label;
        if (part.url) {
            label.href = part.url;
            label.target = '_blank';
            label.rel = 'noopener noreferrer';
        }
        const status = document.createElement('span');
        status.className = 'status';
        status.textContent = '…';
        lastStep.append(label, status);
        toolsBlock.querySelector('ol').append(lastStep);
        // le texte écrit avant la recherche ne doit pas se coller à la suite de la réponse
        if (content && !content.endsWith('\n\n')) content += '\n\n';
        if (reasoning && !reasoning.endsWith('\n\n')) reasoning += '\n\n';
    };
    const finishStep = (part) => {
        if (!lastStep) return;
        lastStep.classList.remove('running');
        lastStep.classList.toggle('failed', !part.ok);
        lastStep.querySelector('.status').textContent = part.ok ? ` · ${part.summary}` : ` · échec : ${part.summary}`;
    };

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
            body: JSON.stringify({ model, messages: history, webSearch }),
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
                } else if (part.type === 'tool') {
                    addStep(part);
                } else if (part.type === 'tool_done') {
                    finishStep(part);
                } else if (part.type === 'notice') {
                    const notice = document.createElement('div');
                    notice.className = 'meta notice-inline';
                    notice.textContent = part.text;
                    bubble.insertBefore(notice, body);
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
        if (toolsBlock) {
            toolsBlock.open = false;
            toolsBlock.querySelectorAll('.step.running').forEach((step) => step.classList.remove('running'));
        }
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
    if (imageMode) {
        if (!question) return;
        prompt.value = '';
        autosize();
        generateImage(question);
        return;
    }
    const ready = (kind) => pending.filter((item) => item.status === 'ready' && item.kind === kind).map((item) => item.data);
    const attachments = ready('file');
    const images = ready('image');
    if ((!question && !attachments.length && !images.length) || !modelSelect.value || pending.some((item) => item.status === 'loading')) return;
    prompt.value = '';
    pending = [];
    renderPending();
    autosize();
    send(question, attachments, images);
});

// Dictée : le micro est enregistré dans le navigateur puis transcrit par Whisper (service speech, sur ce serveur)
const MAX_RECORDING_SECONDS = 300;
const DICTATION_LANGUAGES = { fr: 'français', en: 'anglais' };
let recorder = null;
let recordingTimer = null;
let dictationLanguage = 'fr';
try { if (localStorage.getItem('jin.dictationLanguage') in DICTATION_LANGUAGES) dictationLanguage = localStorage.getItem('jin.dictationLanguage'); } catch {}

function renderDictationLanguage() {
    micLanguageButton.textContent = dictationLanguage.toUpperCase();
    micLanguageButton.setAttribute('aria-label', `Langue de la dictée : ${DICTATION_LANGUAGES[dictationLanguage]}`);
    micLanguageButton.title = `Dictée en ${DICTATION_LANGUAGES[dictationLanguage]} (cliquer pour changer)`;
}

function micMessage(text, isError = false) {
    micStatus.textContent = text;
    micStatus.classList.toggle('error', isError);
    micStatus.hidden = !text;
}

function insertDictation(text) {
    const before = prompt.value.slice(0, prompt.selectionStart);
    const after = prompt.value.slice(prompt.selectionEnd);
    const glue = (left, right) => (left && right && !/\s$/.test(left) && !/^\s/.test(right) ? ' ' : '');
    const inserted = glue(before, text) + text + glue(text, after);
    prompt.value = before + inserted + after;
    const caret = before.length + inserted.length;
    prompt.setSelectionRange(caret, caret);
    autosize();
    prompt.focus();
}

async function startRecording() {
    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
    } catch (e) {
        micMessage(e.name === 'NotAllowedError' ? 'Accès au micro refusé : autorise-le dans le navigateur.' : `Micro indisponible (${e.message}).`, true);
        return;
    }
    const type = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4'].find((t) => MediaRecorder.isTypeSupported(t));
    recorder = new MediaRecorder(stream, type ? { mimeType: type } : {});
    const chunks = [];
    recorder.addEventListener('dataavailable', (event) => { if (event.data.size) chunks.push(event.data); });
    recorder.addEventListener('stop', () => {
        stream.getTracks().forEach((track) => track.stop());
        clearInterval(recordingTimer);
        const blob = new Blob(chunks, { type: recorder.mimeType || type || 'audio/webm' });
        recorder = null;
        micButton.setAttribute('aria-pressed', 'false');
        micButton.setAttribute('aria-label', 'Dicter');
        transcribe(blob);
    });
    recorder.start();
    micButton.setAttribute('aria-pressed', 'true');
    micButton.setAttribute('aria-label', 'Terminer la dictée');
    const started = Date.now();
    const tick = () => {
        const seconds = Math.floor((Date.now() - started) / 1000);
        micMessage(`🔴 Enregistrement… ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')} — re-clique sur le micro pour terminer`);
        if (seconds >= MAX_RECORDING_SECONDS) recorder?.stop();
    };
    tick();
    recordingTimer = setInterval(tick, 500);
}

async function transcribe(blob) {
    if (blob.size < 1000) {
        micMessage('Enregistrement trop court.', true);
        return;
    }
    micButton.classList.add('busy');
    micButton.disabled = true;
    micMessage('Transcription…');
    const extension = blob.type.includes('mp4') ? 'm4a' : blob.type.includes('ogg') ? 'ogg' : 'webm';
    const data = new FormData();
    data.append('audio', blob, `dictee.${extension}`);
    data.append('language', dictationLanguage);
    try {
        const response = await fetch(form.dataset.transcribeUrl, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(body.detail || `HTTP ${response.status}`);
        if (body.text) {
            insertDictation(body.text);
            micMessage('');
        } else {
            micMessage('Rien entendu.', true);
        }
    } catch (e) {
        micMessage(`Transcription impossible : ${e.message}`, true);
    } finally {
        micButton.classList.remove('busy');
        micButton.disabled = false;
    }
}

// le micro n'est accessible qu'en HTTPS (ou sur localhost)
if (navigator.mediaDevices?.getUserMedia && window.MediaRecorder) {
    micButton.hidden = false;
    micLanguageButton.hidden = false;
    renderDictationLanguage();
    micLanguageButton.addEventListener('click', () => {
        dictationLanguage = dictationLanguage === 'fr' ? 'en' : 'fr';
        try { localStorage.setItem('jin.dictationLanguage', dictationLanguage); } catch {}
        renderDictationLanguage();
    });
    micButton.addEventListener('click', () => {
        if (recorder) recorder.stop();
        else startRecording();
    });
}

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
    pending = [];
    renderPending();
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
    const message = history.find((m) => m.role === 'user');
    const first = message?.content.replace(/\s+/g, ' ').trim() || message?.attachments?.[0]?.name || message?.images?.[0]?.name || 'Conversation';
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
            const generated = message.role === 'assistant' && message.images?.length;
            const bubble = addBubble(message.role, message.role === 'user' ? 'Toi' : (generated ? 'Z-Image-Turbo' : conversation.model));
            if (message.role === 'user') {
                fillUserBubble(bubble, message);
            } else if (generated) {
                addImages(bubble, message.images, true);
                bubble.querySelector('.body').remove();
            } else {
                bubble.querySelector('.body').innerHTML = renderMarkdown(message.content);
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
