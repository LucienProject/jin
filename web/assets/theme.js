// Bascule clair / sombre. Sans choix enregistré, on suit le thème du système.
const root = document.documentElement;
const systemDark = window.matchMedia('(prefers-color-scheme: dark)');
const buttons = document.querySelectorAll('.theme-toggle');

const current = () => root.dataset.theme ?? (systemDark.matches ? 'dark' : 'light');

function refresh() {
    const label = current() === 'dark' ? 'Passer au thème clair' : 'Passer au thème sombre';
    buttons.forEach((button) => {
        button.title = label;
        button.setAttribute('aria-label', label);
    });
}

buttons.forEach((button) => button.addEventListener('click', () => {
    const next = current() === 'dark' ? 'light' : 'dark';
    root.dataset.theme = next;
    try { localStorage.setItem('jin.theme', next); } catch {}
    refresh();
}));

systemDark.addEventListener('change', refresh);
refresh();
