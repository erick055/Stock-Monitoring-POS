import { money as peso } from './formatters';
import './analytics-tabs';
document.querySelectorAll('[data-sales-chart]').forEach(chart => {
const dayBars = [...chart.querySelectorAll('[data-day-bar]')];
const chartInsight = chart.querySelector('[data-chart-insight]');
const chartRange = chart.querySelector('[data-chart-range]');

chartRange?.addEventListener('change', () => chartRange.form?.requestSubmit());

function selectDay(bar) {
    dayBars.forEach((item) => item.classList.toggle('is-selected', item === bar));
    if (!chartInsight) return;

    chartInsight.querySelector('span').textContent = bar.dataset.day;
    chartInsight.querySelector('strong').textContent = `${peso.format(Number(bar.dataset.total))} net sales · Gross sales ${peso.format(Number(bar.dataset.gross))} · Refunds ${peso.format(Number(bar.dataset.refunds))}`;
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

const periodMenu = chart.querySelector('[data-chart-period-menu]');
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
