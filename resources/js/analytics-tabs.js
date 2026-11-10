const tabBar = document.querySelector('[data-analytics-tabs]');
if (tabBar) {
    const buttons = [...tabBar.querySelectorAll('[data-analytics-tab]')];
    const panels = [...document.querySelectorAll('[data-analytics-panel]')];
    const keys = buttons.map(button => button.dataset.analyticsTab);
    let active = buttons.find(button => button.getAttribute('aria-selected') === 'true')?.dataset.analyticsTab || 'overview';
    function select(key, focus = false) {
        if (!keys.includes(key)) return;
        active = key;
        buttons.forEach(button => {
            const selected = button.dataset.analyticsTab === key;
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;
            if (selected && focus) button.focus({ preventScroll: true });
        });
        panels.forEach(panel => { panel.hidden = panel.dataset.analyticsPanel !== key; });
        // Date and chart submissions keep the correct panel after navigation.
        panels.forEach(panel => panel.querySelectorAll('form[method="GET"]').forEach(form => {
            let input = form.querySelector('input[name="tab"]');
            if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = 'tab'; form.append(input); }
            input.value = panel.dataset.analyticsPanel;
        }));
        document.dispatchEvent(new CustomEvent('motosync:analytics-tab-changed'));
    }
    function fromUrl() {
        const url = new URL(window.location.href);
        const target = url.hash ? document.getElementById(url.hash.slice(1)) : null;
        const panel = target?.closest('[data-analytics-panel]');
        if (panel) return panel.dataset.analyticsPanel;
        if (keys.includes(url.searchParams.get('tab'))) return url.searchParams.get('tab');
        if (url.searchParams.has('target_page') || url.searchParams.has('target_rows')) return 'targets';
        if (url.searchParams.has('forecast_page') || url.searchParams.has('forecast_rows')) return 'ai';
        if (['speed_page', 'speed_rows', 'slow_page', 'slow_rows'].some(key => url.searchParams.has(key))) return 'movement';
        if (url.searchParams.get('period') === 'day') return 'transactions';
        return 'overview';
    }
    function navigate(key, focus = false) {
        select(key, focus);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', key);
        url.hash = '';
        window.history.pushState({}, '', url);
    }
    buttons.forEach(button => button.addEventListener('click', () => navigate(button.dataset.analyticsTab)));
    document.addEventListener('click', event => {
        const link = event.target.closest('[data-analytics-open]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0) return;
        if (!keys.includes(link.dataset.analyticsOpen)) return;
        event.preventDefault();
        navigate(link.dataset.analyticsOpen, true);
    });
    tabBar.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const index = event.key === 'Home' ? 0 : event.key === 'End' ? keys.length - 1
            : (keys.indexOf(active) + (event.key === 'ArrowRight' ? 1 : -1) + keys.length) % keys.length;
        navigate(keys[index], true);
    });
    window.addEventListener('popstate', () => select(fromUrl()));
    window.addEventListener('hashchange', () => select(fromUrl()));
    document.addEventListener('motosync:table-updated', event => {
        const panel = event.detail.root?.closest('[data-analytics-panel]');
        if (panel) select(panel.dataset.analyticsPanel);
    });
    // Keep server-selected validation errors visible even when the previous URL differs.
    select(document.querySelector('[data-target-validation-error]') ? 'targets' : fromUrl());
}
