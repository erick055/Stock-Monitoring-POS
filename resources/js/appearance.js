import './history-tabs';
import './table-pagination';
import './async-tables';
import './responsive-tables';

const root = document.documentElement;
const button = document.createElement('button');
button.type = 'button';
button.className = 'appearance-toggle';
button.dataset.appearanceToggle = '';
const icon = document.createElement('i');
icon.className = 'bi';
icon.setAttribute('aria-hidden', 'true');
button.append(icon);

function applyTheme(theme) {
    const light = theme === 'light';
    root.dataset.theme = light ? 'light' : 'dark';
    const label = light ? 'Switch to dark mode' : 'Switch to light mode';
    button.setAttribute('aria-label', label);
    button.title = label;
    button.setAttribute('aria-pressed', String(light));
    icon.className = light ? 'bi bi-moon-stars' : 'bi bi-sun';
    window.dispatchEvent(new CustomEvent('motosync:themechange', { detail: { theme: root.dataset.theme } }));
}

applyTheme(root.dataset.theme);
button.addEventListener('click', () => {
    applyTheme(root.dataset.theme === 'light' ? 'dark' : 'light');
    try { localStorage.setItem('motosync-theme', root.dataset.theme); } catch (_) { /* Appearance still works when storage is unavailable. */ }
});
window.addEventListener('storage', (event) => {
    if (event.key === 'motosync-theme') applyTheme(event.newValue);
});
document.body.append(button);
