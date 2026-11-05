import './required-fields';
import './password-visibility';
import './account-dialogs';
import './login-stock-alert';
import './session-activity';

const menu = document.querySelector('[data-menu]');
const sidebar = document.querySelector('[data-sidebar]');

if (menu && sidebar) {
    const backdrop = document.createElement('button');
    backdrop.type = 'button';
    backdrop.className = 'sidebar-backdrop';
    backdrop.setAttribute('aria-label', 'Close navigation');
    document.body.append(backdrop);

    if (!sidebar.id) sidebar.id = 'primary-sidebar';
    menu.setAttribute('aria-controls', sidebar.id);
    const setOpen = (open, returnFocus = false) => {
        sidebar.classList.toggle('open', open);
        document.body.classList.toggle('sidebar-open', open);
        menu.setAttribute('aria-expanded', String(open));
        sidebar.inert = window.innerWidth <= 900 && !open;
        if (open) sidebar.querySelector('a, button')?.focus();
        else if (returnFocus && window.innerWidth <= 900) menu.focus();
    };

    setOpen(false);
    menu.addEventListener('click', () => setOpen(!sidebar.classList.contains('open'), true));
    backdrop.addEventListener('click', () => setOpen(false, true));

    sidebar.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 900) setOpen(false);
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && sidebar.classList.contains('open')) setOpen(false, true);
        if (event.key === 'Tab' && window.innerWidth <= 900 && sidebar.classList.contains('open')) {
            const controls = [...sidebar.querySelectorAll('a[href], button:not(:disabled)')];
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        }
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 900) setOpen(false);
        else sidebar.inert = !sidebar.classList.contains('open');
    });
}

const refreshButton = document.querySelector('[data-dashboard-refresh]');

refreshButton?.addEventListener('click', () => {
    refreshButton.classList.add('refreshing');
    window.location.reload();
});
