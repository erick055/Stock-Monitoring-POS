const menu = document.querySelector('[data-menu]');
const sidebar = document.querySelector('[data-sidebar]');

if (menu && sidebar) {
    const backdrop = document.createElement('button');
    backdrop.type = 'button';
    backdrop.className = 'sidebar-backdrop';
    backdrop.setAttribute('aria-label', 'Close navigation');
    document.body.append(backdrop);

    const setOpen = (open) => {
        sidebar.classList.toggle('open', open);
        document.body.classList.toggle('sidebar-open', open);
        menu.setAttribute('aria-expanded', String(open));
    };

    menu.setAttribute('aria-expanded', 'false');
    menu.addEventListener('click', () => setOpen(!sidebar.classList.contains('open')));
    backdrop.addEventListener('click', () => setOpen(false));

    sidebar.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 900) setOpen(false);
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setOpen(false);
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 900) setOpen(false);
    });
}
