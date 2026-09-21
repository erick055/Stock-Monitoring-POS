const dayBars = [...document.querySelectorAll('[data-day-bar]')];
const chartInsight = document.querySelector('[data-chart-insight]');
const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const chartRange = document.querySelector('[data-chart-range]');

chartRange?.addEventListener('change', () => chartRange.form?.requestSubmit());

function selectDay(bar) {
    dayBars.forEach((item) => item.classList.toggle('is-selected', item === bar));
    if (!chartInsight) return;

    chartInsight.querySelector('span').textContent = bar.dataset.day;
    chartInsight.querySelector('strong').textContent = `${peso.format(Number(bar.dataset.total))} paid sales`;
}

dayBars.forEach((bar) => {
    bar.addEventListener('click', () => selectDay(bar));
    bar.addEventListener('focus', () => selectDay(bar));
    bar.addEventListener('pointerenter', () => selectDay(bar));
});

if (dayBars.length) {
    const highestDay = dayBars.reduce((highest, bar) => (
        Number(bar.dataset.total) > Number(highest.dataset.total) ? bar : highest
    ));
    selectDay(highestDay);
}

const periodMenu = document.querySelector('[data-chart-period-menu]');
const periodToggle = periodMenu?.querySelector('[data-chart-period-toggle]');
const periodOptions = periodMenu?.querySelector('[data-chart-period-options]');

function closePeriodMenu() {
    if (!periodToggle || !periodOptions) return;
    periodToggle.setAttribute('aria-expanded', 'false');
    periodOptions.hidden = true;
}

periodToggle?.addEventListener('click', () => {
    const shouldOpen = periodOptions.hidden;
    periodOptions.hidden = !shouldOpen;
    periodToggle.setAttribute('aria-expanded', String(shouldOpen));
    if (shouldOpen) periodOptions.querySelector('a.is-active, a')?.focus();
});

document.addEventListener('click', (event) => {
    if (periodMenu && !periodMenu.contains(event.target)) closePeriodMenu();
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && periodOptions && !periodOptions.hidden) {
        closePeriodMenu();
        periodToggle.focus();
    }
});

const exportMenu = document.querySelector('[data-export-menu]');
const exportToggle = exportMenu?.querySelector('[data-export-toggle]');
const exportOptions = exportMenu?.querySelector('[data-export-options]');

function closeExportMenu() {
    if (!exportToggle || !exportOptions) return;
    exportToggle.setAttribute('aria-expanded', 'false');
    exportOptions.hidden = true;
}

exportToggle?.addEventListener('click', () => {
    const shouldOpen = exportOptions.hidden;
    exportOptions.hidden = !shouldOpen;
    exportToggle.setAttribute('aria-expanded', String(shouldOpen));
    if (shouldOpen) exportOptions.querySelector('a')?.focus();
});

document.addEventListener('click', (event) => {
    if (exportMenu && !exportMenu.contains(event.target)) closeExportMenu();
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && exportOptions && !exportOptions.hidden) {
        closeExportMenu();
        exportToggle.focus();
    }
});
