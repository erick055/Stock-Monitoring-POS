// Related history panels share one tab bar; switching tabs never navigates away.
const historyGroups = new Map();
document.querySelectorAll('[data-history-panel]').forEach(panel => {
    const key = panel.dataset.historyGroup || panel.id;
    if (!historyGroups.has(key)) historyGroups.set(key, []);
    historyGroups.get(key).push(panel);
});
historyGroups.forEach((group, key) => {
    const panels = [...group].sort((a, b) => Number(a.dataset.historyOrder || 0) - Number(b.dataset.historyOrder || 0));
    const tabs = document.createElement('div');
    tabs.className = 'history-tabs';
    tabs.setAttribute('role', 'tablist');
    tabs.setAttribute('aria-label', 'History and archive views');
    const placeholder = document.createElement('div');
    placeholder.id = key + '-overview';
    placeholder.setAttribute('role', 'tabpanel');
    placeholder.tabIndex = 0;
    placeholder.className = 'history-overview';
    placeholder.textContent = 'History is hidden. Select a tab to view saved records.';
    const views = [placeholder, ...panels];
    const buttons = views.map((view, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.id = view.id + '-tab';
        button.textContent = index === 0 ? 'Hide history' : view.dataset.historyPanel;
        button.setAttribute('role', 'tab');
        button.setAttribute('aria-controls', view.id);
        view.setAttribute('role', 'tabpanel');
        view.setAttribute('aria-labelledby', button.id);
        view.tabIndex = 0;
        tabs.append(button);
        return button;
    });
    group[0].before(tabs, placeholder);
    let selected = 0;
    const select = (index, focus = false) => {
        selected = index;
        views.forEach((view, position) => {
            view.hidden = index !== position;
            buttons[position].setAttribute('aria-selected', String(index === position));
            buttons[position].tabIndex = index === position ? 0 : -1;
        });
        if (focus) buttons[index].focus();
    };
    buttons.forEach((button, index) => button.addEventListener('click', () => select(index)));
    tabs.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const index = event.key === 'Home' ? 0 : event.key === 'End' ? views.length - 1
            : (selected + (event.key === 'ArrowRight' ? 1 : -1) + views.length) % views.length;
        select(index, true);
    });
    const hashIndex = () => views.findIndex(view => window.location.hash === '#' + view.id);
    const query = new URLSearchParams(window.location.search);
    const requested = panels.findIndex(panel => query.has(panel.dataset.historyPage) || query.has(panel.dataset.historySize));
    const validationPanel = panels.findIndex(panel => panel.dataset.historySelected === 'true');
    select(validationPanel >= 0 ? validationPanel + 1 : hashIndex() >= 0 ? hashIndex() : requested >= 0 ? requested + 1 : 0);
    window.addEventListener('hashchange', () => { if (hashIndex() >= 0) select(hashIndex()); });
    document.addEventListener('motosync:table-updated', event => {
        const index = views.indexOf(event.detail.root);
        if (index < 1) return;
        const anchor = event.detail.url ? new URL(event.detail.url).hash : window.location.hash;
        if (selected === index || anchor === '#' + views[index].id) select(index);
    });
});
